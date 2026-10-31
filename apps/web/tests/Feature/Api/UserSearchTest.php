<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\ContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_are_found_by_username_prefix_and_display_name(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer']);
        User::factory()->create(['username' => 'alice', 'name' => 'Alice Wonder']);
        User::factory()->create(['username' => 'bob', 'name' => 'Bob Builder']);

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users?q=ali')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'alice')
            ->assertJsonPath('data.0.relationship', ContactService::RELATIONSHIP_NONE);

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users?q=wonder')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'alice');
    }

    public function test_search_never_returns_the_actor_or_users_hidden_by_a_block(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer']);

        $blocked = User::factory()->create(['username' => 'blocked_one', 'name' => 'Match Me']);
        $blocker = User::factory()->create(['username' => 'blocked_two', 'name' => 'Match Me Too']);
        User::factory()->create(['username' => 'visible_one', 'name' => 'Match Me Three']);

        $contacts = app(ContactService::class);
        $contacts->block($viewer, $blocked);
        $contacts->block($blocker, $viewer);

        $response = $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users?q=match')
            ->assertOk();

        $usernames = array_column($response->json('data'), 'username');

        $this->assertSame(['visible_one'], $usernames);
        $this->assertNotContains('viewer', $usernames);
    }

    public function test_search_reports_the_relationship_with_each_user(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer']);
        $contact = User::factory()->create(['username' => 'contact_user']);
        $sent = User::factory()->create(['username' => 'sent_user']);
        $received = User::factory()->create(['username' => 'received_user']);
        User::factory()->create(['username' => 'stranger_user']);

        $contacts = app(ContactService::class);

        $request = $contacts->sendRequest($viewer, $contact);
        $contacts->accept($contact, $request);

        $contacts->sendRequest($viewer, $sent);
        $contacts->sendRequest($received, $viewer);

        $relationships = collect(
            $this->actingAs($viewer)->getJson('/api/v1/users?q=user')->json('data'),
        )->pluck('relationship', 'username')->all();

        $this->assertSame(ContactService::RELATIONSHIP_CONTACT, $relationships['contact_user']);
        $this->assertSame(ContactService::RELATIONSHIP_REQUEST_SENT, $relationships['sent_user']);
        $this->assertSame(ContactService::RELATIONSHIP_REQUEST_RECEIVED, $relationships['received_user']);
        $this->assertSame(ContactService::RELATIONSHIP_NONE, $relationships['stranger_user']);
    }

    public function test_search_requires_a_query(): void
    {
        $viewer = User::factory()->create();

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }
}
