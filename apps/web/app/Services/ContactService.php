<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ContactRequestStatus;
use App\Models\Contact;
use App\Models\ContactRequest;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Address book, contact requests and blocks.
 *
 * Every rule that protects users from unwanted contact lives here so the
 * conversation and message services can reuse it: a blocked pair cannot start a
 * direct conversation or exchange direct messages.
 */
class ContactService
{
    public const SEARCH_LIMIT = 20;

    public const RELATIONSHIP_CONTACT = 'contact';

    public const RELATIONSHIP_REQUEST_SENT = 'request_sent';

    public const RELATIONSHIP_REQUEST_RECEIVED = 'request_received';

    public const RELATIONSHIP_NONE = 'none';

    public function __construct(
        private readonly RealtimeEventPublisher $events,
        private readonly PresenceService $presence,
        private readonly PrivacyService $privacy,
    ) {}

    /**
     * Search active users by username or display name.
     *
     * Users a block hides (in either direction) never show up, which keeps the
     * search result consistent with what the pair is allowed to do.
     *
     * @return Collection<int, User>
     */
    public function search(User $actor, string $query): Collection
    {
        $term = Str::lower(trim($query));

        if ($term === '') {
            return collect();
        }

        $hidden = $this->hiddenUserIds($actor);

        $users = User::query()
            ->where('status', AccountStatus::Active)
            ->whereKeyNot($actor->id)
            ->when($hidden !== [], fn (Builder $builder): Builder => $builder->whereKeyNot($hidden))
            ->where(function (Builder $builder) use ($term): void {
                $builder->where('username', 'like', $term.'%')
                    ->orWhere('username', 'like', '%'.$term.'%')
                    ->orWhere('name', 'like', '%'.$term.'%');
            })
            ->orderByRaw('CASE WHEN username LIKE ? THEN 0 ELSE 1 END', [$term.'%'])
            ->orderBy('username')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        $users = $this->privacy->filterVisibleProfiles($actor, $users);

        $this->presence->annotate($users, $actor);

        $relationships = $this->relationshipsFor($actor, $users);

        return $users->each(
            fn (User $user): User => $user->setAttribute(
                'relationship',
                $relationships[$user->id] ?? self::RELATIONSHIP_NONE,
            ),
        );
    }

    /**
     * Describe how the actor relates to each user, for badges and action menus.
     *
     * @param  iterable<int, User>  $users
     * @return array<string, string>
     */
    public function relationshipsFor(User $actor, iterable $users): array
    {
        $ids = collect($users)->map(fn (User $user): string => (string) $user->id)->all();

        if ($ids === []) {
            return [];
        }

        $contacts = Contact::query()
            ->where('user_id', $actor->id)
            ->whereIn('contact_id', $ids)
            ->pluck('contact_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $sent = ContactRequest::query()
            ->where('sender_id', $actor->id)
            ->whereIn('receiver_id', $ids)
            ->where('status', ContactRequestStatus::Pending)
            ->pluck('receiver_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $received = ContactRequest::query()
            ->where('receiver_id', $actor->id)
            ->whereIn('sender_id', $ids)
            ->where('status', ContactRequestStatus::Pending)
            ->pluck('sender_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $relationships = [];

        foreach ($ids as $id) {
            $relationships[$id] = match (true) {
                in_array($id, $contacts, true) => self::RELATIONSHIP_CONTACT,
                in_array($id, $sent, true) => self::RELATIONSHIP_REQUEST_SENT,
                in_array($id, $received, true) => self::RELATIONSHIP_REQUEST_RECEIVED,
                default => self::RELATIONSHIP_NONE,
            };
        }

        return $relationships;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function contactsFor(User $actor): Collection
    {
        $contacts = Contact::query()
            ->where('user_id', $actor->id)
            ->with('contact')
            ->get();

        $this->presence->annotate(
            $contacts->map(fn (Contact $contact): User => $contact->contact),
            $actor,
        );

        return $contacts->sortBy(
            fn (Contact $contact): string => Str::lower($contact->nickname ?? $contact->contact->name),
        )->values();
    }

    /**
     * Pending requests only: an answered request stays in the table as history,
     * but it must never surface again. Listing every status made an accepted or
     * rejected request reappear as soon as the client refetched (the realtime
     * `contact.request.updated` event triggers exactly that), which looked like
     * the response had not been saved.
     *
     * @return Collection<int, ContactRequest>
     */
    public function requestsFor(User $actor, string $direction = 'incoming'): Collection
    {
        $query = ContactRequest::query()
            ->with(['sender', 'receiver'])
            ->where('status', ContactRequestStatus::Pending)
            ->orderByDesc('created_at');

        $query = match ($direction) {
            'outgoing' => $query->where('sender_id', $actor->id),
            'all' => $query->where(function (Builder $builder) use ($actor): void {
                $builder->where('sender_id', $actor->id)
                    ->orWhere('receiver_id', $actor->id);
            }),
            default => $query->where('receiver_id', $actor->id),
        };

        $requests = $query->get();

        $this->presence->annotate($requests->flatMap(
            fn (ContactRequest $request): array => [$request->sender, $request->receiver],
        ), $actor);

        return $requests;
    }

    public function sendRequest(User $actor, User $target, ?string $message = null): ContactRequest
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'username' => __('You cannot add yourself as a contact.'),
            ]);
        }

        if (! $target->isActive()) {
            throw ValidationException::withMessages([
                'username' => __('This account is not available.'),
            ]);
        }

        if (! $this->privacy->canReceiveContactRequest($actor, $target)) {
            throw ValidationException::withMessages([
                'username' => __('This user is not accepting contact requests.'),
            ]);
        }

        if ($this->hasBlockBetween($actor, $target)) {
            throw ValidationException::withMessages([
                'username' => __('You cannot send a contact request to this user.'),
            ]);
        }

        if ($this->isContact($actor, $target)) {
            throw ValidationException::withMessages([
                'username' => __('This user is already in your contacts.'),
            ]);
        }

        $incoming = ContactRequest::query()
            ->where('sender_id', $target->id)
            ->where('receiver_id', $actor->id)
            ->first();

        if ($incoming !== null && $incoming->status === ContactRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'username' => __('This user has already sent you a contact request.'),
            ]);
        }

        $request = ContactRequest::query()->firstOrNew([
            'sender_id' => $actor->id,
            'receiver_id' => $target->id,
        ]);

        $request->fill([
            'status' => ContactRequestStatus::Pending,
            'message' => $message,
            'responded_at' => null,
        ]);

        DB::transaction(function () use ($request): void {
            $request->save();

            $this->events->contactRequestCreated($request, (string) $request->receiver_id);
        });

        return $request->load(['sender', 'receiver']);
    }

    public function accept(User $actor, ContactRequest $request): ContactRequest
    {
        $this->assertReceiver($actor, $request);
        $this->assertPending($request);

        if ($this->hasBlockBetween($actor, $request->sender)) {
            throw ValidationException::withMessages([
                'request' => __('You cannot accept this request.'),
            ]);
        }

        DB::transaction(function () use ($request): void {
            $request->forceFill([
                'status' => ContactRequestStatus::Accepted,
                'responded_at' => Carbon::now(),
            ])->save();

            $this->createContactPair((string) $request->sender_id, (string) $request->receiver_id);

            $this->events->contactRequestUpdated($request, [
                (string) $request->sender_id,
                (string) $request->receiver_id,
            ]);
        });

        return $request->load(['sender', 'receiver']);
    }

    public function reject(User $actor, ContactRequest $request): ContactRequest
    {
        $this->assertReceiver($actor, $request);
        $this->assertPending($request);

        DB::transaction(function () use ($request): void {
            $request->forceFill([
                'status' => ContactRequestStatus::Rejected,
                'responded_at' => Carbon::now(),
            ])->save();

            $this->events->contactRequestUpdated($request, [
                (string) $request->sender_id,
                (string) $request->receiver_id,
            ]);
        });

        return $request->load(['sender', 'receiver']);
    }

    public function cancel(User $actor, ContactRequest $request): ContactRequest
    {
        if ($request->sender_id !== $actor->id) {
            throw ValidationException::withMessages([
                'request' => __('Only the sender can cancel a contact request.'),
            ]);
        }

        $this->assertPending($request);

        DB::transaction(function () use ($request): void {
            $request->forceFill([
                'status' => ContactRequestStatus::Cancelled,
                'responded_at' => Carbon::now(),
            ])->save();

            $this->events->contactRequestUpdated($request, [
                (string) $request->sender_id,
                (string) $request->receiver_id,
            ]);
        });

        return $request->load(['sender', 'receiver']);
    }

    public function updateNickname(User $actor, User $target, ?string $nickname): Contact
    {
        $contact = Contact::query()
            ->where('user_id', $actor->id)
            ->where('contact_id', $target->id)
            ->firstOrFail();

        $contact->forceFill(['nickname' => $nickname])->save();

        return $contact->load('contact');
    }

    /**
     * Remove the contact in both directions: address books are symmetric once a
     * request was accepted.
     */
    public function removeContact(User $actor, User $target): void
    {
        $deleted = Contact::query()
            ->where(function (Builder $builder) use ($actor, $target): void {
                $builder->where('user_id', $actor->id)->where('contact_id', $target->id);
            })
            ->orWhere(function (Builder $builder) use ($actor, $target): void {
                $builder->where('user_id', $target->id)->where('contact_id', $actor->id);
            })
            ->delete();

        if ($deleted > 0) {
            $this->events->contactUpdated((string) $actor->id, (string) $target->id);
        }
    }

    /**
     * Blocking drops the contact pair and closes pending requests, so an unblock
     * starts from a clean slate instead of a half-removed address book.
     */
    public function block(User $actor, User $target, ?string $reason = null): UserBlock
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'username' => __('You cannot block yourself.'),
            ]);
        }

        $block = UserBlock::query()->firstOrCreate(
            ['blocker_id' => $actor->id, 'blocked_id' => $target->id],
            ['reason' => $reason],
        );

        DB::transaction(function () use ($actor, $target): void {
            $this->removeContact($actor, $target);

            ContactRequest::query()
                ->where(function (Builder $builder) use ($actor, $target): void {
                    $builder->where('sender_id', $actor->id)->where('receiver_id', $target->id);
                })
                ->orWhere(function (Builder $builder) use ($actor, $target): void {
                    $builder->where('sender_id', $target->id)->where('receiver_id', $actor->id);
                })
                ->where('status', ContactRequestStatus::Pending)
                ->update([
                    'status' => ContactRequestStatus::Cancelled,
                    'responded_at' => Carbon::now(),
                ]);

            $this->events->blockUpdated((string) $actor->id, (string) $target->id, true);
        });

        return $block->load(['blocker', 'blocked']);
    }

    public function unblock(User $actor, User $target): void
    {
        $deleted = UserBlock::query()
            ->where('blocker_id', $actor->id)
            ->where('blocked_id', $target->id)
            ->delete();

        if ($deleted > 0) {
            $this->events->blockUpdated((string) $actor->id, (string) $target->id, false);
        }
    }

    /**
     * @return Collection<int, UserBlock>
     */
    public function blocksFor(User $actor): Collection
    {
        $blocks = UserBlock::query()
            ->where('blocker_id', $actor->id)
            ->with('blocked')
            ->orderByDesc('created_at')
            ->get();

        $this->presence->annotate(
            $blocks->map(fn (UserBlock $block): User => $block->blocked),
            $actor,
        );

        return $blocks;
    }

    public function isContact(User $actor, User $other): bool
    {
        return Contact::query()
            ->where('user_id', $actor->id)
            ->where('contact_id', $other->id)
            ->exists();
    }

    /**
     * A block in either direction makes the pair "blocked": neither side may
     * start a direct conversation or send the other a direct message.
     */
    public function hasBlockBetween(User $first, User $second): bool
    {
        return UserBlock::query()
            ->where(function (Builder $builder) use ($first, $second): void {
                $builder->where('blocker_id', $first->id)->where('blocked_id', $second->id);
            })
            ->orWhere(function (Builder $builder) use ($first, $second): void {
                $builder->where('blocker_id', $second->id)->where('blocked_id', $first->id);
            })
            ->exists();
    }

    /**
     * Ids that must not appear in the actor's search results: people they
     * blocked and people who blocked them.
     *
     * @return array<int, string>
     */
    private function hiddenUserIds(User $actor): array
    {
        return UserBlock::query()
            ->where('blocker_id', $actor->id)
            ->orWhere('blocked_id', $actor->id)
            ->get(['blocker_id', 'blocked_id'])
            ->flatMap(fn (UserBlock $block): array => [$block->blocker_id, $block->blocked_id])
            ->reject(fn (string $id): bool => $id === $actor->id)
            ->unique()
            ->values()
            ->all();
    }

    private function createContactPair(string $first, string $second): void
    {
        Contact::query()->firstOrCreate(['user_id' => $first, 'contact_id' => $second]);
        Contact::query()->firstOrCreate(['user_id' => $second, 'contact_id' => $first]);
    }

    private function assertReceiver(User $actor, ContactRequest $request): void
    {
        if ($request->receiver_id !== $actor->id) {
            throw ValidationException::withMessages([
                'request' => __('Only the receiver can respond to a contact request.'),
            ]);
        }
    }

    private function assertPending(ContactRequest $request): void
    {
        if (! $request->status->isPending()) {
            throw ValidationException::withMessages([
                'request' => __('This contact request has already been answered.'),
            ]);
        }
    }
}
