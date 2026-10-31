<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\GroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversationStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_conversation_can_be_muted_forever_and_unmuted(): void
    {
        [$actor, , $conversation] = $this->direct();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/mute", ['is_muted' => true])
            ->assertOk()
            ->assertJsonPath('data.is_muted', true)
            ->assertJsonPath('data.archived', false);

        $this->assertNotNull($this->membership($conversation, $actor)->muted_until);

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/mute", ['is_muted' => false])
            ->assertOk()
            ->assertJsonPath('data.is_muted', false)
            ->assertJsonPath('data.muted_until', null);
    }

    public function test_muting_can_be_limited_to_a_specific_moment(): void
    {
        [$actor, , $conversation] = $this->direct();
        $until = Carbon::now()->addHours(3)->startOfSecond();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/mute", [
                'is_muted' => true,
                'until' => $until->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.is_muted', true);

        $this->assertTrue($until->equalTo(Carbon::parse($this->membership($conversation, $actor)->muted_until)));
    }

    public function test_mute_only_affects_the_actor(): void
    {
        [$actor, $other, $conversation] = $this->direct();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/mute", ['is_muted' => true])
            ->assertOk();

        $this
            ->actingAs($other)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.is_muted', false);
    }

    public function test_conversations_can_be_archived_and_restored(): void
    {
        [$actor, , $conversation] = $this->direct();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/archive", ['archived' => true])
            ->assertOk()
            ->assertJsonPath('data.archived', true);

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/archive", ['archived' => false])
            ->assertOk()
            ->assertJsonPath('data.archived', false);
    }

    public function test_outsiders_cannot_change_mute_or_archive_state(): void
    {
        [, , $conversation] = $this->direct();
        $outsider = User::factory()->create();

        $this
            ->actingAs($outsider)
            ->postJson("/api/v1/conversations/{$conversation->id}/mute", ['is_muted' => true])
            ->assertForbidden();

        $this
            ->actingAs($outsider)
            ->postJson("/api/v1/conversations/{$conversation->id}/archive", ['archived' => true])
            ->assertForbidden();
    }

    public function test_groups_can_be_muted_too(): void
    {
        $owner = User::factory()->create();
        $group = app(GroupService::class)->create($owner, ['title' => 'Team']);

        $this
            ->actingAs($owner)
            ->postJson("/api/v1/conversations/{$group->id}/mute", ['is_muted' => true])
            ->assertOk()
            ->assertJsonPath('data.is_muted', true);
    }

    public function test_mute_requires_a_boolean_flag(): void
    {
        [$actor, , $conversation] = $this->direct();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/conversations/{$conversation->id}/mute", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_muted');
    }

    /**
     * @return array{0: User, 1: User, 2: Conversation}
     */
    private function direct(): array
    {
        $actor = User::factory()->create();
        $other = User::factory()->create(['username' => 'alice']);

        $conversation = app(ConversationService::class)->openDirect($actor, $other);

        return [$actor, $other, $conversation];
    }

    private function membership(Conversation $conversation, User $user): object
    {
        return DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->sole();
    }
}
