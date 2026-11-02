<?php

namespace Tests\Feature\Api;

use App\Models\ContactRequest;
use App\Models\User;
use App\Services\ContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_contact_request_is_created_pending(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['username' => 'alice']);

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/contact-requests', [
                'username' => 'ALICE',
                'message' => 'Let us connect',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.direction', 'outgoing')
            ->assertJsonPath('data.sender.id', $actor->id)
            ->assertJsonPath('data.receiver.id', $target->id);

        $this->assertDatabaseHas('contact_requests', [
            'sender_id' => $actor->id,
            'receiver_id' => $target->id,
            'status' => 'pending',
            'message' => 'Let us connect',
        ]);
    }

    public function test_accepting_a_request_creates_a_symmetric_contact_pair(): void
    {
        [$actor, $target, $request] = $this->pendingRequest();

        $this
            ->actingAs($target)
            ->postJson("/api/v1/contact-requests/{$request->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.direction', 'incoming');

        $this->assertDatabaseHas('contacts', ['user_id' => $actor->id, 'contact_id' => $target->id]);
        $this->assertDatabaseHas('contacts', ['user_id' => $target->id, 'contact_id' => $actor->id]);

        $this
            ->actingAs($actor)
            ->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.username', 'alice');
    }

    public function test_only_the_receiver_can_answer_a_request(): void
    {
        [$actor, , $request] = $this->pendingRequest();
        $stranger = User::factory()->create();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/contact-requests/{$request->id}/accept")
            ->assertForbidden();

        $this
            ->actingAs($stranger)
            ->postJson("/api/v1/contact-requests/{$request->id}/reject")
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status->value);
    }

    public function test_rejecting_a_request_does_not_create_contacts(): void
    {
        [, $target, $request] = $this->pendingRequest();

        $this
            ->actingAs($target)
            ->postJson("/api/v1/contact-requests/{$request->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_the_sender_can_cancel_their_own_request(): void
    {
        [$actor, , $request] = $this->pendingRequest();

        $this
            ->actingAs($actor)
            ->postJson("/api/v1/contact-requests/{$request->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_requests_can_be_listed_by_direction(): void
    {
        [$actor, $target, $request] = $this->pendingRequest();

        $this
            ->actingAs($target)
            ->getJson('/api/v1/contact-requests?direction=incoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $request->id);

        $this
            ->actingAs($target)
            ->getJson('/api/v1/contact-requests?direction=outgoing')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this
            ->actingAs($actor)
            ->getJson('/api/v1/contact-requests?direction=outgoing')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_contact_can_be_nicknamed_and_removed(): void
    {
        [$actor, $target, $request] = $this->pendingRequest();
        app(ContactService::class)->accept($target, $request);

        $this
            ->actingAs($actor)
            ->patchJson("/api/v1/contacts/{$target->username}", ['nickname' => 'Ally'])
            ->assertOk()
            ->assertJsonPath('data.nickname', 'Ally');

        $this
            ->actingAs($actor)
            ->deleteJson("/api/v1/contacts/{$target->username}")
            ->assertNoContent();

        $this->assertDatabaseCount('contacts', 0);
    }

    /**
     * An answered request is history: it must disappear from both sides of the
     * pending lists, otherwise accepting looks like it did nothing.
     */
    public function test_answered_requests_leave_the_pending_lists(): void
    {
        [$actor, $target, $accepted] = $this->pendingRequest();
        $other = User::factory()->create(['username' => 'bob']);
        $rejected = app(ContactService::class)->sendRequest($other, $target);

        app(ContactService::class)->accept($target, $accepted);
        app(ContactService::class)->reject($target, $rejected);

        $this
            ->actingAs($target)
            ->getJson('/api/v1/contact-requests?direction=incoming')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this
            ->actingAs($actor)
            ->getJson('/api/v1/contact-requests?direction=outgoing')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this
            ->actingAs($other)
            ->getJson('/api/v1/contact-requests?direction=outgoing')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('contact_requests', [
            'id' => $accepted->id,
            'status' => 'accepted',
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: ContactRequest}
     */
    private function pendingRequest(): array
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['username' => 'alice']);

        $request = app(ContactService::class)->sendRequest($actor, $target);

        return [$actor, $target, $request];
    }
}
