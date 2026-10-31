<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\ConversationInvite;
use App\Models\User;
use App\Services\GroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GroupInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_managers_can_create_invite_links(): void
    {
        [$owner, $member, $group] = $this->group();

        $this
            ->actingAs($member)
            ->postJson("/api/v1/groups/{$group->id}/invites")
            ->assertForbidden();

        $response = $this
            ->actingAs($owner)
            ->postJson("/api/v1/groups/{$group->id}/invites", ['max_uses' => 5])
            ->assertCreated()
            ->assertJsonPath('data.max_uses', 5)
            ->assertJsonPath('data.is_active', true);

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertSame(url('/join/'.$token), $response->json('data.url'));
        $this->assertDatabaseHas('conversation_invites', [
            'conversation_id' => $group->id,
            'created_by' => $owner->id,
            'token' => $token,
        ]);
    }

    public function test_invites_are_listed_and_revocable(): void
    {
        [$owner, , $group] = $this->group();

        $groups = app(GroupService::class);
        $invite = $groups->createInvite($owner, $group);

        $this
            ->actingAs($owner)
            ->getJson("/api/v1/groups/{$group->id}/invites")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.token', $invite->token);

        $this
            ->actingAs($owner)
            ->deleteJson("/api/v1/groups/{$group->id}/invites/{$invite->id}")
            ->assertNoContent();

        $this->assertNotNull($invite->fresh()->revoked_at);
    }

    public function test_an_invite_preview_shows_the_group(): void
    {
        [$owner, , $group] = $this->group();
        $invite = app(GroupService::class)->createInvite($owner, $group);
        $outsider = User::factory()->create();

        $this
            ->actingAs($outsider)
            ->getJson("/api/v1/invites/{$invite->token}")
            ->assertOk()
            ->assertJsonPath('data.conversation.id', $group->id)
            ->assertJsonPath('data.conversation.title', 'Team')
            ->assertJsonPath('data.conversation.member_count', 2)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_redeeming_an_invite_joins_the_group_once(): void
    {
        [$owner, , $group] = $this->group();
        $invite = app(GroupService::class)->createInvite($owner, $group);
        $joiner = User::factory()->create(['username' => 'joiner']);

        $this
            ->actingAs($joiner)
            ->postJson("/api/v1/invites/{$invite->token}/join")
            ->assertOk()
            ->assertJsonPath('data.id', $group->id)
            ->assertJsonPath('data.viewer_role', 'member');

        $this->assertSame(1, $invite->fresh()->uses);

        $this
            ->actingAs($joiner)
            ->postJson("/api/v1/invites/{$invite->token}/join")
            ->assertOk()
            ->assertJsonPath('data.id', $group->id);

        $this->assertSame(1, $invite->fresh()->uses);
        $this->assertSame(
            1,
            $group->members()->whereKey($joiner->id)->whereNull('conversation_members.left_at')->count(),
        );
    }

    public function test_revoked_expired_and_exhausted_invites_are_rejected(): void
    {
        [$owner, , $group] = $this->group();
        $groups = app(GroupService::class);
        $joiner = User::factory()->create();

        $revoked = $groups->createInvite($owner, $group);
        $groups->revokeInvite($owner, $group, $revoked);

        $this
            ->actingAs($joiner)
            ->postJson("/api/v1/invites/{$revoked->token}/join")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $expired = ConversationInvite::query()->create([
            'conversation_id' => $group->id,
            'created_by' => $owner->id,
            'token' => ConversationInvite::generateToken(),
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $this
            ->actingAs($joiner)
            ->postJson("/api/v1/invites/{$expired->token}/join")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $exhausted = $groups->createInvite($owner, $group, null, 1);

        $this
            ->actingAs(User::factory()->create())
            ->postJson("/api/v1/invites/{$exhausted->token}/join")
            ->assertOk();

        $this
            ->actingAs($joiner)
            ->postJson("/api/v1/invites/{$exhausted->token}/join")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_unknown_tokens_return_a_validation_error(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->getJson('/api/v1/invites/does-not-exist')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function group(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['username' => 'alice']);

        $group = app(GroupService::class)->create($owner, [
            'title' => 'Team',
            'member_ids' => [$member->id],
        ]);

        return [$owner, $member, $group];
    }
}
