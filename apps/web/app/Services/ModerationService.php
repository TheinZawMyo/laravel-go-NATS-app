<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Account-level moderation: suspend (temporary), ban (indefinite) and
 * reactivate. The session middleware already logs out inactive accounts on
 * their next request; this service additionally revokes push devices so a
 * suspended account stops receiving notifications immediately.
 */
class ModerationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function suspend(User $moderator, User $target, ?string $reason = null): User
    {
        return $this->apply($moderator, $target, AccountStatus::Suspended, $reason);
    }

    public function ban(User $moderator, User $target, ?string $reason = null): User
    {
        return $this->apply($moderator, $target, AccountStatus::Banned, $reason);
    }

    public function reactivate(User $moderator, User $target): User
    {
        $this->assertModeratable($moderator, $target);

        DB::transaction(function () use ($moderator, $target): void {
            $target->forceFill(['status' => AccountStatus::Active])->save();

            $this->audit->record('user.reactivated', $moderator, $target);
        });

        return $target->refresh();
    }

    private function apply(
        User $moderator,
        User $target,
        AccountStatus $status,
        ?string $reason,
    ): User {
        $this->assertModeratable($moderator, $target);

        DB::transaction(function () use ($moderator, $target, $status, $reason): void {
            $target->forceFill(['status' => $status])->save();

            UserDevice::query()
                ->where('user_id', $target->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            // Only the database session driver has rows to remove; Redis-backed
            // sessions are expired by the account-status middleware on the next
            // request. Both paths leave the account logged out.
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }

            $this->audit->record(
                'user.'.$status->value,
                $moderator,
                $target,
                ['reason' => $reason],
            );
        });

        return $target->refresh();
    }

    private function assertModeratable(User $moderator, User $target): void
    {
        if ($moderator->is($target)) {
            throw ValidationException::withMessages([
                'user' => __('You cannot moderate your own account.'),
            ]);
        }

        if ($target->isAdmin()) {
            throw ValidationException::withMessages([
                'user' => __('Administrator accounts cannot be moderated here.'),
            ]);
        }
    }
}
