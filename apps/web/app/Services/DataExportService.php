<?php

namespace App\Services;

use App\Enums\ConversationRole;
use App\Enums\ConversationType;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserDevice;
use App\Models\UserReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the machine-readable account export.
 *
 * Secrets (password hash, two-factor secret, recovery codes, remember token)
 * are never included. Message bodies are included only for messages the user
 * authored; conversations list metadata rather than every member's history.
 */
class DataExportService
{
    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'bio' => $user->bio,
                'status_text' => $user->status_text,
                'status' => $user->status->value,
                'avatar_url' => $user->avatar_url,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'deleted_at' => $user->deleted_at,
            ],
            'privacy' => [
                'last_seen_visibility' => $user->last_seen_visibility->value,
                'profile_visibility' => $user->profile_visibility->value,
                'contact_requests_policy' => $user->contact_requests_policy->value,
            ],
            'contacts' => $this->contacts($user),
            'blocks' => $this->blocks($user),
            'conversations' => $this->conversations($user),
            'messages' => $this->messages($user),
            'reports' => $this->reports($user),
            'devices' => $this->devices($user),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contacts(User $user): array
    {
        return Contact::query()
            ->with('contact')
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Contact $contact): array => [
                'username' => $contact->contact?->username,
                'nickname' => $contact->nickname,
                'created_at' => $contact->created_at,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blocks(User $user): array
    {
        return UserBlock::query()
            ->with('blocked')
            ->where('blocker_id', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (UserBlock $block): array => [
                'username' => $block->blocked?->username,
                'reason' => $block->reason,
                'created_at' => $block->created_at,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conversations(User $user): array
    {
        return Conversation::query()
            ->whereHas('members', function (Builder $query) use ($user): void {
                $query->whereKey($user->id)
                    ->whereNull('conversation_members.left_at');
            })
            ->with('members')
            ->orderBy('created_at')
            ->get()
            ->map(function (Conversation $conversation) use ($user): array {
                $membership = $conversation->members->firstWhere('id', $user->id);

                return [
                    'id' => $conversation->id,
                    'type' => $conversation->type === ConversationType::Group ? 'group' : 'direct',
                    'title' => $conversation->title,
                    'role' => $membership?->pivot?->role instanceof ConversationRole
                        ? $membership->pivot->role->value
                        : null,
                    'joined_at' => $membership?->pivot?->joined_at,
                    'last_read_seq' => (int) ($membership?->pivot?->last_read_seq ?? 0),
                    'muted_until' => $membership?->pivot?->muted_until,
                    'archived_at' => $membership?->pivot?->archived_at,
                    'created_at' => $conversation->created_at,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function messages(User $user): array
    {
        return Message::query()
            ->withTrashed()
            ->with('attachments')
            ->where('sender_id', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $message): array => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'seq' => $message->seq,
                'type' => $message->type->value,
                'body' => $message->deleted_at === null ? $message->body : null,
                'reply_to_message_id' => $message->reply_to_message_id,
                'edited_at' => $message->edited_at,
                'deleted_at' => $message->deleted_at,
                'created_at' => $message->created_at,
                'attachments' => $message->attachments
                    ->map(fn ($attachment): array => [
                        'original_name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'size_bytes' => $attachment->size_bytes,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reports(User $user): array
    {
        return UserReport::query()
            ->with('reportedUser')
            ->where('reporter_id', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (UserReport $report): array => [
                'id' => $report->id,
                'reported_username' => $report->reportedUser?->username,
                'reported_message_id' => $report->reported_message_id,
                'reason' => $report->reason->value,
                'details' => $report->details,
                'status' => $report->status->value,
                'created_at' => $report->created_at,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function devices(User $user): array
    {
        return UserDevice::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (UserDevice $device): array => [
                'name' => $device->name,
                'platform' => $device->platform,
                'last_active_at' => $device->last_active_at,
                'revoked_at' => $device->revoked_at,
                'created_at' => $device->created_at,
            ])
            ->all();
    }
}
