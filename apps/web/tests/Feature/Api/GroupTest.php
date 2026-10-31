<?php

namespace Tests\Feature\Api;

use App\Enums\ConversationRole;
use App\Models\Conversation;
use App\Models\User;
use App\Services\GroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_group_with_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['username' => 'alice']);

        $this
            ->actingAs($owner)
            ->postJson('/api/v1/groups', [
                'title' => '  Weekend Plans  ',
                'description' => 'Where to go',
                'member_ids' => [$member->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'group')
            ->assertJsonPath('data.title', 'Weekend Plans')
            ->assertJsonPath('data.description', 'Where to go')
            ->assertJsonPath('data.viewer_role', 'owner')
            ->assertJsonCount(2, 'data.members');

        $group = Conversation::query()->sole();

        $this->assertSame('owner', $this->roleOf($group, $owner));
        $this->assertSame('member', $this->roleOf($group, $member));
        $this->assertSame($owner->id, $group->created_by);
    }

    public function test_plain_members_cannot_manage_the_group(): void
    {
        [, $member, $group] = $this->group();
        $outsider = User::factory()->create(['username' => 'outsider']);

        $this
            ->actingAs($member)
            ->postJson("/api/v1/groups/{$group->id}/members", ['user_ids' => [$outsider->id]])
            ->assertForbidden();

        $this
            ->actingAs($member)
            ->patchJson("/api/v1/groups/{$group->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this
            ->actingAs($outsider)
            ->patchJson("/api/v1/groups/{$group->id}", ['title' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_admins_can_add_members_but_only_the_owner_assigns_roles(): void
    {
        [$owner, $member, $group] = $this->group();
        $newcomer = User::factory()->create(['username' => 'newcomer']);

        $this
            ->actingAs($owner)
            ->postJson("/api/v1/groups/{$group->id}/members/{$member->username}/role", [
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.viewer_role', 'owner');

        $this->assertSame('admin', $this->roleOf($group, $member));

        $this
            ->actingAs($member)
            ->postJson("/api/v1/groups/{$group->id}/members", ['user_ids' => [$newcomer->id]])
            ->assertOk()
            ->assertJsonCount(3, 'data.members');

        $this
            ->actingAs($member)
            ->postJson("/api/v1/groups/{$group->id}/members/{$owner->username}/role", [
                'role' => 'member',
            ])
            ->assertForbidden();
    }

    public function test_roles_must_be_assignable_and_targets_must_be_members(): void
    {
        [$owner, $member, $group] = $this->group();

        $this
            ->actingAs($owner)
            ->postJson("/api/v1/groups/{$group->id}/members/{$member->username}/role", [
                'role' => 'owner',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this
            ->actingAs($owner)
            ->deleteJson("/api/v1/groups/{$group->id}/members/ghost")
            ->assertNotFound();
    }

    public function test_members_can_only_be_removed_by_a_higher_role(): void
    {
        [$owner, $member, $group] = $this->group();
        $other = User::factory()->create(['username' => 'other']);

        $groups = app(GroupService::class);
        $groups->changeRole($owner, $group, $member, ConversationRole::Admin);
        $groups->addMembers($owner, $group, [$other->id]);

        $this
            ->actingAs($member)
            ->deleteJson("/api/v1/groups/{$group->id}/members/{$other->username}")
            ->assertOk();

        $this->assertNotNull($this->membership($group, $other)->left_at);

        $this
            ->actingAs($owner)
            ->deleteJson("/api/v1/groups/{$group->id}/members/{$owner->username}")
            ->assertUnprocessable();
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

    private function roleOf(Conversation $group, User $user): ?string
    {
        return $this->membership($group, $user)?->role;
    }

    private function membership(Conversation $group, User $user): ?object
    {
        return DB::table('conversation_members')
            ->where('conversation_id', $group->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
