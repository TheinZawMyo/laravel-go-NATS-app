<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ContactService;
use App\Services\GroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_leaving_hands_the_group_to_the_oldest_member(): void
    {
        [$owner, $member, $group] = $this->group();

        $this
            ->actingAs($owner)
            ->postJson("/api/v1/groups/{$group->id}/leave")
            ->assertNoContent();

        $this->assertDatabaseHas('conversation_members', [
            'conversation_id' => $group->id,
            'user_id' => $member->id,
            'role' => 'owner',
            'left_at' => null,
        ]);

        $this->assertSame($member->id, $group->fresh()->created_by);
        $this->assertDatabaseMissing('conversation_members', [
            'conversation_id' => $group->id,
            'user_id' => $owner->id,
            'left_at' => null,
        ]);

        $this
            ->actingAs($owner)
            ->getJson("/api/v1/conversations/{$group->id}")
            ->assertForbidden();
    }

    public function test_the_last_member_leaving_deletes_the_group(): void
    {
        $owner = User::factory()->create();
        $group = app(GroupService::class)->create($owner, ['title' => 'Solo']);

        $this
            ->actingAs($owner)
            ->postJson("/api/v1/groups/{$group->id}/leave")
            ->assertNoContent();

        $this->assertDatabaseMissing('conversations', ['id' => $group->id]);
    }

    public function test_outsiders_cannot_leave_a_group_they_do_not_belong_to(): void
    {
        [, , $group] = $this->group();
        $outsider = User::factory()->create();

        $this
            ->actingAs($outsider)
            ->postJson("/api/v1/groups/{$group->id}/leave")
            ->assertForbidden();
    }

    public function test_members_are_listed_with_their_roles(): void
    {
        [$owner, $member, $group] = $this->group();

        $payload = $this
            ->actingAs($member)
            ->getJson("/api/v1/groups/{$group->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data.members')
            ->assertJsonPath('data.viewer_role', 'member')
            ->json('data');

        $roles = collect($payload['members'])
            ->mapWithKeys(fn (array $item): array => [$item['user']['id'] => $item['role']])
            ->all();

        $this->assertSame('owner', $roles[$owner->id]);
        $this->assertSame('member', $roles[$member->id]);
    }

    public function test_group_profile_can_be_updated_by_a_manager(): void
    {
        [$owner, , $group] = $this->group();

        $this
            ->actingAs($owner)
            ->patchJson("/api/v1/groups/{$group->id}", [
                'title' => 'Renamed',
                'description' => 'New description',
                'history_visible_to_new_members' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.description', 'New description')
            ->assertJsonPath('data.history_visible_to_new_members', false);
    }

    public function test_adding_a_blocked_user_is_rejected(): void
    {
        [$owner, , $group] = $this->group();
        $blocked = User::factory()->create(['username' => 'blocked', 'name' => 'Blocked']);

        app(ContactService::class)->block($owner, $blocked);

        $this
            ->actingAs($owner)
            ->postJson("/api/v1/groups/{$group->id}/members", ['user_ids' => [$blocked->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids');
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
