<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\E2eeService;
use App\Services\GroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class E2eeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_read_their_public_identity_key(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->postJson('/api/v1/e2ee/keys', [
                'public_key' => 'identity-public-key',
                'fingerprint' => 'fingerprint-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.public_key', 'identity-public-key')
            ->assertJsonPath('data.algorithm', E2eeService::IDENTITY_ALGORITHM);

        $this
            ->actingAs($user)
            ->getJson('/api/v1/e2ee/keys')
            ->assertOk()
            ->assertJsonPath('data.fingerprint', 'fingerprint-1');

        $this
            ->actingAs($user)
            ->postJson('/api/v1/e2ee/keys', [
                'public_key' => 'attacker-controlled-key',
                'fingerprint' => 'attacker-controlled-fingerprint',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('public_key');
    }

    public function test_a_user_can_store_and_read_an_encrypted_identity_backup(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->putJson('/api/v1/e2ee/backup', [
                'ciphertext' => 'backup-ciphertext',
                'salt' => 'backup-salt',
                'iv' => 'backup-iv',
                'fingerprint' => 'fingerprint-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.ciphertext', 'backup-ciphertext')
            ->assertJsonPath('data.kdf', 'PBKDF2-SHA256')
            ->assertJsonPath('data.iterations', 600000);

        $this
            ->actingAs($user)
            ->getJson('/api/v1/e2ee/backup')
            ->assertOk()
            ->assertJsonPath('data.salt', 'backup-salt');
    }

    public function test_publishing_a_conversation_key_requires_every_member_identity(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();

        $this->registerIdentity($actor);
        $this->registerIdentity($other);

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/e2ee/keys", [
                'key_version' => 1,
                'wraps' => [$this->wrap($other->id)],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wraps');

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/e2ee/keys", [
                'key_version' => 1,
                'wraps' => [
                    $this->wrap($actor->id),
                    $this->wrap($other->id),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.key_version', 1)
            ->assertJsonPath('data.keys.0.key_version', 1)
            ->assertJsonPath('data.keys.0.wrap.ciphertext', 'wrapped-'.$actor->id);

        $this->assertTrue($conversation->fresh()->e2ee_enabled);
    }

    public function test_an_encrypted_conversation_rejects_plaintext_and_stores_ciphertext_only(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $this->enableE2ee($actor, $other, $conversation);

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => (string) Str::uuid(),
                'body' => 'plaintext leak',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('encryption');

        $clientMessageId = (string) Str::uuid();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => $clientMessageId,
                'encryption' => [
                    'algorithm' => E2eeService::MESSAGE_ALGORITHM,
                    'key_version' => 1,
                    'iv' => 'message-iv',
                    'ciphertext' => 'message-ciphertext',
                    'aad' => "{$conversation->id}:{$clientMessageId}:1",
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_encrypted', true)
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.encryption.ciphertext', 'message-ciphertext');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'client_message_id' => $clientMessageId,
            'body' => null,
            'is_encrypted' => true,
        ]);
    }

    public function test_editing_an_encrypted_message_binds_the_ciphertext_to_the_message(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $this->enableE2ee($actor, $other, $conversation);

        $clientMessageId = (string) Str::uuid();

        $messageId = $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => $clientMessageId,
                'encryption' => [
                    'algorithm' => E2eeService::MESSAGE_ALGORITHM,
                    'key_version' => 1,
                    'iv' => 'message-iv',
                    'ciphertext' => 'message-ciphertext',
                    'aad' => "{$conversation->id}:{$clientMessageId}:1",
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->actingAs($actor)
            ->patchJson("/api/v1/messages/{$messageId}", [
                'encryption' => [
                    'algorithm' => E2eeService::MESSAGE_ALGORITHM,
                    'key_version' => 1,
                    'iv' => 'edited-iv',
                    'ciphertext' => 'edited-ciphertext',
                    'aad' => "{$conversation->id}:{$messageId}:1",
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.encryption.ciphertext', 'edited-ciphertext');

        $this->assertDatabaseHas('message_edits', [
            'message_id' => $messageId,
            'current_body' => null,
            'current_encryption->ciphertext' => 'edited-ciphertext',
        ]);
    }

    public function test_encrypted_upload_presign_requires_the_current_conversation_key(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $this->enableE2ee($actor, $other, $conversation);

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'is_encrypted' => true,
                'conversation_id' => $conversation->id,
                'key_version' => 2,
                'iv' => 'attachment-content-iv',
                'key_iv' => 'attachment-iv',
                'key_ciphertext' => 'attachment-key',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key_version');

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'is_encrypted' => true,
                'conversation_id' => $conversation->id,
                'key_version' => 1,
                'iv' => 'attachment-content-iv',
                'key_iv' => 'attachment-iv',
                'key_ciphertext' => 'attachment-key',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_encrypted', true)
            ->assertJsonPath('data.key_version', 1)
            ->assertJsonPath('data.key_ciphertext', 'attachment-key');
    }

    public function test_sending_is_blocked_until_a_required_key_rotation_completes(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $this->enableE2ee($actor, $other, $conversation);
        $conversation->forceFill(['e2ee_rotation_required' => true])->save();
        $clientMessageId = (string) Str::uuid();

        $this->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_message_id' => $clientMessageId,
                'encryption' => [
                    'algorithm' => E2eeService::MESSAGE_ALGORITHM,
                    'key_version' => 1,
                    'iv' => 'message-iv',
                    'ciphertext' => 'message-ciphertext',
                    'aad' => "{$conversation->id}:{$clientMessageId}:1",
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('encryption');

        $this->actingAs($actor)
            ->postJson('/api/v1/uploads/presign', [
                'original_name' => 'private.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 64,
                'is_encrypted' => true,
                'conversation_id' => $conversation->id,
                'key_version' => 1,
                'iv' => 'attachment-content-iv',
                'key_iv' => 'attachment-iv',
                'key_ciphertext' => 'attachment-key',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversation_id');

        $state = app(E2eeService::class)->publishConversationKey($actor, $conversation, [
            'key_version' => 2,
            'wraps' => [$this->wrap($actor->id), $this->wrap($other->id)],
        ]);

        $this->assertFalse($state['rotation_required']);
        $this->assertSame(2, $state['key_version']);
        $this->assertFalse($conversation->fresh()->e2ee_rotation_required);
    }

    public function test_removed_group_member_loses_server_side_old_key_wraps(): void
    {
        $actor = User::factory()->create();
        $member = User::factory()->create();
        $group = app(GroupService::class)->create($actor, [
            'title' => 'Encrypted group',
            'member_ids' => [$member->id],
        ]);
        $this->registerIdentity($actor);
        $this->registerIdentity($member);

        app(E2eeService::class)->publishConversationKey($actor, $group, [
            'key_version' => 1,
            'wraps' => [$this->wrap($actor->id), $this->wrap($member->id)],
        ]);

        app(GroupService::class)->removeMember($actor, $group, $member);

        $this->assertDatabaseMissing('conversation_key_wraps', [
            'recipient_user_id' => $member->id,
        ]);
        $this->assertTrue($group->fresh()->e2ee_rotation_required);
    }

    public function test_rejoining_group_member_is_not_given_the_old_key_wrap_again(): void
    {
        $actor = User::factory()->create();
        $member = User::factory()->create();
        $group = app(GroupService::class)->create($actor, [
            'title' => 'Encrypted group',
            'member_ids' => [$member->id],
        ]);
        $this->registerIdentity($actor);
        $this->registerIdentity($member);
        app(E2eeService::class)->publishConversationKey($actor, $group, [
            'key_version' => 1,
            'wraps' => [$this->wrap($actor->id), $this->wrap($member->id)],
        ]);

        $groups = app(GroupService::class);
        $groups->removeMember($actor, $group, $member);
        $groups->addMembers($actor, $group, [$member->id]);
        $state = app(E2eeService::class)->stateFor($member, $group->fresh());

        $this->assertTrue($state['rotation_required']);
        $this->assertNull($state['keys'][0]['wrap']);
    }

    public function test_key_versions_cannot_skip_ahead_without_rotation_state(): void
    {
        [$actor, $other, $conversation] = $this->directConversation();
        $this->registerIdentity($actor);
        $this->registerIdentity($other);

        $this->expectException(ValidationException::class);

        app(E2eeService::class)->publishConversationKey($actor, $conversation, [
            'key_version' => 5,
            'wraps' => [$this->wrap($actor->id), $this->wrap($other->id)],
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function directConversation(): array
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();

        return [
            $actor,
            $other,
            app(ConversationService::class)->openDirect($actor, $other),
        ];
    }

    private function registerIdentity(User $user): void
    {
        app(E2eeService::class)->registerIdentity($user, [
            'public_key' => 'public-'.$user->id,
            'algorithm' => E2eeService::IDENTITY_ALGORITHM,
            'fingerprint' => 'fingerprint-'.$user->id,
        ]);
    }

    private function enableE2ee(User $actor, User $other, Conversation $conversation): void
    {
        $this->registerIdentity($actor);
        $this->registerIdentity($other);

        app(E2eeService::class)->publishConversationKey($actor, $conversation, [
            'key_version' => 1,
            'wraps' => [
                $this->wrap($actor->id),
                $this->wrap($other->id),
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function wrap(string $userId): array
    {
        return [
            'recipient_user_id' => $userId,
            'algorithm' => E2eeService::WRAP_ALGORITHM,
            'ephemeral_public_key' => 'ephemeral-'.$userId,
            'iv' => 'wrap-iv-'.$userId,
            'ciphertext' => 'wrapped-'.$userId,
        ];
    }
}
