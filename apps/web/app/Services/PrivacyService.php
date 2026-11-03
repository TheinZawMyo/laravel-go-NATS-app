<?php

namespace App\Services;

use App\Enums\ContactRequestPolicy;
use App\Enums\LastSeenVisibility;
use App\Enums\ProfileVisibility;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Reads a user's privacy settings and answers the questions every other
 * service needs to enforce them: can this viewer see the profile, the last
 * seen timestamp, or send a contact request?
 *
 * The checks are intentionally centralized so the API, the search results and
 * the presence annotations can never disagree about what a setting means.
 */
class PrivacyService
{
    public function isContact(User $viewer, User $target): bool
    {
        return Contact::query()
            ->where('user_id', $viewer->id)
            ->where('contact_id', $target->id)
            ->exists();
    }

    public function canSeeLastSeen(User $viewer, User $target): bool
    {
        if ($viewer->id === $target->id) {
            return true;
        }

        return match ($target->last_seen_visibility) {
            LastSeenVisibility::Everyone => true,
            LastSeenVisibility::Contacts => $this->isContact($viewer, $target),
            LastSeenVisibility::Nobody => false,
        };
    }

    public function canViewProfile(User $viewer, User $target): bool
    {
        if ($viewer->id === $target->id) {
            return true;
        }

        return match ($target->profile_visibility) {
            ProfileVisibility::Everyone => true,
            ProfileVisibility::Contacts => $this->isContact($viewer, $target),
        };
    }

    public function canReceiveContactRequest(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        return match ($target->contact_requests_policy) {
            ContactRequestPolicy::Everyone => true,
            ContactRequestPolicy::Contacts => $this->isContact($actor, $target),
            ContactRequestPolicy::Nobody => false,
        };
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public function filterVisibleProfiles(User $viewer, Collection $users): Collection
    {
        $contactIds = $this->contactIdsFor($viewer, $users->pluck('id')->all());

        return $users->filter(
            fn (User $user): bool => $this->profileVisibleFor(
                $viewer,
                $user,
                in_array($user->id, $contactIds, true),
            ),
        )->values();
    }

    /**
     * A presence annotation is only safe when the viewer is allowed to see the
     * member's last seen state. The map is computed in bulk so a group with a
     * hundred members still costs one contact query.
     *
     * @param  array<int, User>  $users
     * @return array<string, bool>
     */
    public function lastSeenVisibleMap(User $viewer, array $users): array
    {
        $contactIds = $this->contactIdsFor(
            $viewer,
            array_map(static fn (User $user): string => $user->id, $users),
        );

        $map = [];

        foreach ($users as $user) {
            $map[$user->id] = match ($user->last_seen_visibility) {
                LastSeenVisibility::Everyone => true,
                LastSeenVisibility::Contacts => $user->id === $viewer->id
                    || in_array($user->id, $contactIds, true),
                LastSeenVisibility::Nobody => $user->id === $viewer->id,
            };
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $userIds
     * @return array<int, string>
     */
    public function contactIdsFor(User $viewer, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Contact::query()
            ->where('user_id', $viewer->id)
            ->whereIn('contact_id', $userIds)
            ->pluck('contact_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    private function profileVisibleFor(User $viewer, User $target, bool $isContact): bool
    {
        if ($viewer->id === $target->id) {
            return true;
        }

        return match ($target->profile_visibility) {
            ProfileVisibility::Everyone => true,
            ProfileVisibility::Contacts => $isContact,
        };
    }
}
