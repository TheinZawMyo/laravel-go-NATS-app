<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationE2eeKey;
use App\Models\ConversationKeyWrap;
use App\Models\E2eeIdentityKey;
use App\Models\E2eeKeyBackup;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Server-side half of end-to-end encryption.
 *
 * This service only ever handles public keys and ciphertext. Message bodies,
 * conversation keys, and private identity keys are encrypted in the browser,
 * so the application and database cannot read them. The server's job is to
 * authorize membership, keep key versions ordered, and deliver the wrapped
 * conversation key that belongs to the current user.
 */
class E2eeService
{
    public const IDENTITY_ALGORITHM = 'ECDH-P256';

    public const MESSAGE_ALGORITHM = 'AES-GCM';

    public const WRAP_ALGORITHM = 'ECDH-P256+HKDF-SHA256+A256GCM';

    public const MAX_KEY_VERSION = 100000;

    public function __construct(
        private readonly RealtimeEventPublisher $events,
    ) {}

    public function identityFor(User $user): ?E2eeIdentityKey
    {
        return E2eeIdentityKey::query()->where('user_id', $user->id)->first();
    }

    /**
     * Register or replace the public identity key for the signed-in user.
     *
     * @param  array{public_key: string, algorithm: string, fingerprint: string}  $data
     */
    public function registerIdentity(User $user, array $data): E2eeIdentityKey
    {
        return DB::transaction(function () use ($user, $data): E2eeIdentityKey {
            $key = E2eeIdentityKey::query()->firstOrNew(['user_id' => $user->id]);

            if ($key->exists) {
                $sameIdentity = $key->public_key === $data['public_key']
                    && $key->algorithm === $data['algorithm']
                    && $key->fingerprint === $data['fingerprint'];

                if (! $sameIdentity) {
                    throw ValidationException::withMessages([
                        'public_key' => __('An encryption identity is already registered. Use the verified key rotation flow to replace it.'),
                    ]);
                }

                return $key;
            }

            $key->fill([
                'public_key' => $data['public_key'],
                'algorithm' => $data['algorithm'],
                'fingerprint' => $data['fingerprint'],
            ])->save();

            return $key;
        });
    }

    public function backupFor(User $user): ?E2eeKeyBackup
    {
        return E2eeKeyBackup::query()->where('user_id', $user->id)->first();
    }

    /**
     * @param  array{ciphertext: string, salt: string, iv: string, kdf: string, iterations: int, fingerprint?: string|null}  $data
     */
    public function storeBackup(User $user, array $data): E2eeKeyBackup
    {
        return DB::transaction(function () use ($user, $data): E2eeKeyBackup {
            $backup = E2eeKeyBackup::query()->firstOrNew(['user_id' => $user->id]);

            $backup->fill([
                'ciphertext' => $data['ciphertext'],
                'salt' => $data['salt'],
                'iv' => $data['iv'],
                'kdf' => $data['kdf'],
                'iterations' => $data['iterations'],
                'fingerprint' => $data['fingerprint'] ?? null,
            ])->save();

            return $backup;
        });
    }

    /**
     * Everything a client needs to decrypt its own copy of the conversation
     * key: every key version with the viewer's wrap, plus the public keys of
     * active members so a client can create the next wrap set.
     *
     * @return array<string, mixed>
     */
    public function stateFor(User $viewer, Conversation $conversation): array
    {
        $members = $conversation->members()
            ->whereNull('conversation_members.left_at')
            ->get();

        $memberKeys = E2eeIdentityKey::query()
            ->whereIn('user_id', $members->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $keys = ConversationE2eeKey::query()
            ->with(['wraps' => function ($query) use ($viewer): void {
                $query->where('recipient_user_id', $viewer->id);
            }])
            ->where('conversation_id', $conversation->id)
            ->orderBy('key_version')
            ->get();

        return [
            'enabled' => (bool) $conversation->e2ee_enabled,
            'key_version' => $conversation->e2ee_key_version !== null
                ? (int) $conversation->e2ee_key_version
                : null,
            'rotation_required' => (bool) $conversation->e2ee_rotation_required,
            'keys' => $keys->map(function (ConversationE2eeKey $key): array {
                /** @var ConversationKeyWrap|null $wrap */
                $wrap = $key->wraps->first();

                return [
                    'key_version' => (int) $key->key_version,
                    'created_by' => $key->created_by,
                    'created_at' => $key->created_at,
                    'wrap' => $wrap !== null ? [
                        'algorithm' => $wrap->algorithm,
                        'ephemeral_public_key' => $wrap->ephemeral_public_key,
                        'iv' => $wrap->iv,
                        'ciphertext' => $wrap->ciphertext,
                    ] : null,
                ];
            })->values()->all(),
            'member_keys' => $members->map(function (User $member) use ($memberKeys): array {
                $key = $memberKeys->get($member->id);

                return [
                    'user_id' => (string) $member->id,
                    'public_key' => $key?->public_key,
                    'algorithm' => $key?->algorithm,
                    'fingerprint' => $key?->fingerprint,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Publish a conversation key version and its per-member wraps.
     *
     * A new version must cover every active member, otherwise someone would
     * silently lose access to messages sent with it. Adding a member to an
     * existing version only appends missing wraps; existing wraps are never
     * overwritten, because that would let one member lock another out.
     *
     * @param  array{key_version: int, wraps: list<array<string, mixed>>}  $data
     * @return array<string, mixed>
     */
    public function publishConversationKey(User $actor, Conversation $conversation, array $data): array
    {
        $keyVersion = (int) $data['key_version'];

        if ($keyVersion < 1 || $keyVersion > self::MAX_KEY_VERSION) {
            throw ValidationException::withMessages([
                'key_version' => __('The encryption key version is out of range.'),
            ]);
        }

        $wraps = collect($data['wraps'])
            ->keyBy(fn (array $wrap): string => (string) $wrap['recipient_user_id']);

        $result = DB::transaction(function () use ($actor, $conversation, $keyVersion, $wraps): Conversation {
            $lockedConversation = Conversation::query()
                ->lockForUpdate()
                ->findOrFail($conversation->id);
            $activeIds = array_values($lockedConversation->members()
                ->whereNull('conversation_members.left_at')
                ->pluck('users.id')
                ->map(fn ($id): string => (string) $id)
                ->all());

            if ($activeIds === []) {
                throw ValidationException::withMessages([
                    'wraps' => __('The conversation has no active members.'),
                ]);
            }

            if (array_diff($wraps->keys()->all(), $activeIds) !== []) {
                throw ValidationException::withMessages([
                    'wraps' => __('Every wrap must target an active conversation member.'),
                ]);
            }

            $membersWithKeys = array_values(E2eeIdentityKey::query()
                ->whereIn('user_id', $activeIds)
                ->pluck('user_id')
                ->map(fn ($id): string => (string) $id)
                ->all());
            $missingIdentities = array_values(array_diff($activeIds, $membersWithKeys));

            if ($missingIdentities !== []) {
                throw ValidationException::withMessages([
                    'wraps' => __('Every conversation member must set up encryption before it can be enabled.'),
                    'missing_user_ids' => $missingIdentities,
                ]);
            }

            $currentVersion = (int) ($lockedConversation->e2ee_key_version ?? 0);
            $existing = ConversationE2eeKey::query()
                ->where('conversation_id', $lockedConversation->id)
                ->where('key_version', $keyVersion)
                ->lockForUpdate()
                ->first();

            // Permit a retry of the already-published current key, but never
            // allow clients to skip versions or publish unsolicited versions.
            if (
                $lockedConversation->e2ee_enabled &&
                ! $lockedConversation->e2ee_rotation_required &&
                $keyVersion === $currentVersion &&
                $existing !== null
            ) {
                return $lockedConversation;
            }

            if (
                $keyVersion !== $currentVersion + 1 ||
                ($lockedConversation->e2ee_enabled && ! $lockedConversation->e2ee_rotation_required)
            ) {
                throw ValidationException::withMessages([
                    'key_version' => __('A key rotation is not currently required or the key version is stale.'),
                ]);
            }

            if ($existing === null) {
                $this->assertCoversEveryMember(
                    array_values($wraps->keys()->all()),
                    $activeIds,
                );

                $key = ConversationE2eeKey::query()->create([
                    'conversation_id' => $conversation->id,
                    'key_version' => $keyVersion,
                    'created_by' => $actor->id,
                ]);

                $this->insertWraps($key, $wraps->all());
            } else {
                $existingRecipients = ConversationKeyWrap::query()
                    ->where('conversation_key_id', $existing->id)
                    ->pluck('recipient_user_id')
                    ->map(fn ($id): string => (string) $id)
                    ->all();

                $missingWraps = array_values(array_diff($activeIds, $existingRecipients));

                if ($missingWraps === []) {
                    // Already fully distributed; keep the endpoint idempotent.
                    return $conversation;
                }

                $newWraps = $wraps->only($missingWraps)->all();

                if (array_diff($missingWraps, array_keys($newWraps)) !== []) {
                    throw ValidationException::withMessages([
                        'wraps' => __('A wrap is required for every member who does not have the conversation key.'),
                    ]);
                }

                $this->insertWraps($existing, $newWraps);
            }

            $lockedConversation->forceFill([
                'e2ee_enabled' => true,
                'e2ee_key_version' => $keyVersion,
                'e2ee_rotation_required' => false,
            ])->save();

            return $lockedConversation;
        });

        $this->events->conversationUpdated($result, 'e2ee_key_updated');

        return $this->stateFor($actor, $result->refresh());
    }

    /**
     * Validate the encryption envelope on a new message.
     *
     * The AAD binds the ciphertext to the conversation and client message id,
     * so a ciphertext cannot be replayed into another chat or another send.
     *
     * @param  array<string, mixed>  $encryption
     */
    public function assertSendEncryption(Conversation $conversation, array $encryption, string $clientMessageId): void
    {
        $this->assertConversationIsEncrypted($conversation);

        $keyVersion = (int) ($encryption['key_version'] ?? 0);

        if ($keyVersion !== (int) $conversation->e2ee_key_version) {
            throw ValidationException::withMessages([
                'encryption.key_version' => __('This message uses an outdated encryption key.'),
            ]);
        }

        if (! $this->keyVersionExists($conversation, $keyVersion)) {
            throw ValidationException::withMessages([
                'encryption.key_version' => __('The encryption key for this conversation is missing.'),
            ]);
        }

        $expectedAad = $conversation->id.':'.$clientMessageId.':'.$keyVersion;

        if (($encryption['aad'] ?? null) !== $expectedAad) {
            throw ValidationException::withMessages([
                'encryption.aad' => __('The encrypted message is not bound to this conversation.'),
            ]);
        }
    }

    public function assertConversationReadyForSend(Conversation $conversation): void
    {
        if ($conversation->e2ee_enabled && $conversation->e2ee_rotation_required) {
            throw ValidationException::withMessages([
                'encryption' => __('Encryption keys must be rotated before sending in this conversation.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $encryption
     */
    public function assertEditEncryption(Message $message, array $encryption): void
    {
        $this->assertConversationReadyForSend($message->conversation);

        $keyVersion = (int) ($encryption['key_version'] ?? 0);

        if ($keyVersion !== (int) $message->conversation->e2ee_key_version) {
            throw ValidationException::withMessages([
                'encryption.key_version' => __('This message uses an outdated encryption key.'),
            ]);
        }

        $expectedAad = $message->conversation_id.':'.$message->id.':'.$keyVersion;

        if (($encryption['aad'] ?? null) !== $expectedAad) {
            throw ValidationException::withMessages([
                'encryption.aad' => __('The encrypted message is not bound to this message.'),
            ]);
        }
    }

    private function assertConversationIsEncrypted(Conversation $conversation): void
    {
        if (! $conversation->e2ee_enabled) {
            throw ValidationException::withMessages([
                'encryption' => __('This conversation is not end-to-end encrypted.'),
            ]);
        }
    }

    private function keyVersionExists(Conversation $conversation, int $keyVersion): bool
    {
        return ConversationE2eeKey::query()
            ->where('conversation_id', $conversation->id)
            ->where('key_version', $keyVersion)
            ->exists();
    }

    /**
     * @param  list<string>  $recipientIds
     * @param  list<string>  $activeIds
     */
    private function assertCoversEveryMember(array $recipientIds, array $activeIds): void
    {
        if (array_diff($activeIds, $recipientIds) !== []) {
            throw ValidationException::withMessages([
                'wraps' => __('A wrap is required for every active conversation member.'),
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $wraps
     */
    private function insertWraps(ConversationE2eeKey $key, array $wraps): void
    {
        $now = Carbon::now();

        foreach ($wraps as $recipientId => $wrap) {
            DB::table('conversation_key_wraps')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'conversation_key_id' => $key->id,
                'recipient_user_id' => $recipientId,
                'algorithm' => (string) ($wrap['algorithm'] ?? self::WRAP_ALGORITHM),
                'ephemeral_public_key' => (string) $wrap['ephemeral_public_key'],
                'iv' => (string) $wrap['iv'],
                'ciphertext' => (string) $wrap['ciphertext'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
