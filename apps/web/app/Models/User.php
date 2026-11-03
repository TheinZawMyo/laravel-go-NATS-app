<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ContactRequestPolicy;
use App\Enums\LastSeenVisibility;
use App\Enums\ProfileVisibility;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $avatar_key
 * @property string|null $bio
 * @property string|null $status_text
 * @property AccountStatus $status
 * @property bool $is_admin
 * @property LastSeenVisibility $last_seen_visibility
 * @property ProfileVisibility $profile_visibility
 * @property ContactRequestPolicy $contact_requests_policy
 * @property Carbon|null $last_seen_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string|null $avatar_url
 * @property-read ConversationMember|null $pivot
 */
#[Fillable(['name', 'username', 'email', 'password', 'avatar_key', 'bio', 'status_text', 'last_seen_at', 'last_seen_visibility', 'profile_visibility', 'contact_requests_policy', 'is_admin'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'is_admin' => false,
        'last_seen_visibility' => 'everyone',
        'profile_visibility' => 'everyone',
        'contact_requests_policy' => 'everyone',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'status' => AccountStatus::class,
            'is_admin' => 'boolean',
            'last_seen_visibility' => LastSeenVisibility::class,
            'profile_visibility' => ProfileVisibility::class,
            'contact_requests_policy' => ContactRequestPolicy::class,
        ];
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasMany<ContactRequest, $this>
     */
    public function contactRequestsSent(): HasMany
    {
        return $this->hasMany(ContactRequest::class, 'sender_id');
    }

    /**
     * @return HasMany<ContactRequest, $this>
     */
    public function contactRequestsReceived(): HasMany
    {
        return $this->hasMany(ContactRequest::class, 'receiver_id');
    }

    /**
     * @return HasMany<UserBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocker_id');
    }

    /**
     * @return HasMany<UserBlock, $this>
     */
    public function blockedBy(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocked_id');
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * @return HasMany<UserReport, $this>
     */
    public function reportsFiled(): HasMany
    {
        return $this->hasMany(UserReport::class, 'reporter_id');
    }

    /**
     * @return HasMany<UserReport, $this>
     */
    public function reportsReceived(): HasMany
    {
        return $this->hasMany(UserReport::class, 'reported_user_id');
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    /**
     * Get the user's avatar URL when a media key is present.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->avatar_key === null) {
                return null;
            }

            return Storage::disk('public')->url($this->avatar_key);
        });
    }
}
