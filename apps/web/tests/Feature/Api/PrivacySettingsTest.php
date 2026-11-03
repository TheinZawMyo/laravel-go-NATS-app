<?php

namespace Tests\Feature\Api;

use App\Enums\ContactRequestPolicy;
use App\Enums\LastSeenVisibility;
use App\Enums\ProfileVisibility;
use App\Models\Contact;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_settings_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch('/settings/privacy', [
                'last_seen_visibility' => 'contacts',
                'profile_visibility' => 'contacts',
                'contact_requests_policy' => 'nobody',
            ])
            ->assertRedirect('/settings/privacy')
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame(LastSeenVisibility::Contacts, $user->last_seen_visibility);
        $this->assertSame(ProfileVisibility::Contacts, $user->profile_visibility);
        $this->assertSame(ContactRequestPolicy::Nobody, $user->contact_requests_policy);
    }

    public function test_privacy_settings_are_validated(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch('/settings/privacy', [
                'last_seen_visibility' => 'sometimes',
                'profile_visibility' => 'friends',
                'contact_requests_policy' => 'anyone',
            ])
            ->assertSessionHasErrors([
                'last_seen_visibility',
                'profile_visibility',
                'contact_requests_policy',
            ]);
    }

    public function test_contact_only_profiles_are_hidden_from_search_and_lookup(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer']);
        $private = User::factory()->create([
            'username' => 'private_user',
            'profile_visibility' => ProfileVisibility::Contacts,
        ]);

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users?q=private')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users/private_user')
            ->assertNotFound();

        Contact::query()->create(['user_id' => $viewer->id, 'contact_id' => $private->id]);

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users?q=private')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'private_user');

        $this
            ->actingAs($viewer)
            ->getJson('/api/v1/users/private_user')
            ->assertOk();
    }

    public function test_last_seen_is_hidden_by_visibility_setting(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer']);
        $hidden = User::factory()->create([
            'username' => 'hidden',
            'last_seen_visibility' => LastSeenVisibility::Nobody,
            'last_seen_at' => now(),
        ]);

        $conversation = app(ConversationService::class)->openDirect($viewer, $hidden);

        $this
            ->actingAs($viewer)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.participant.username', 'hidden')
            ->assertJsonPath('data.participant.online', false)
            ->assertJsonPath('data.participant.last_seen_at', null);
    }

    public function test_contacts_can_see_last_seen_when_the_setting_allows_contacts(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer']);
        $target = User::factory()->create([
            'username' => 'target',
            'last_seen_visibility' => LastSeenVisibility::Contacts,
            'last_seen_at' => now()->subMinute(),
        ]);

        $conversation = app(ConversationService::class)->openDirect($viewer, $target);

        $this
            ->actingAs($viewer)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.participant.last_seen_at', null);

        Contact::query()->create(['user_id' => $viewer->id, 'contact_id' => $target->id]);

        $this
            ->actingAs($viewer)
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.participant.last_seen_at', $target->last_seen_at->toJSON());
    }

    public function test_contact_requests_respect_the_policy(): void
    {
        $actor = User::factory()->create(['username' => 'actor']);
        $closed = User::factory()->create([
            'username' => 'closed',
            'contact_requests_policy' => ContactRequestPolicy::Nobody,
        ]);

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/contact-requests', ['username' => 'closed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $contactsOnly = User::factory()->create([
            'username' => 'contacts_only',
            'contact_requests_policy' => ContactRequestPolicy::Contacts,
        ]);

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/contact-requests', ['username' => 'contacts_only'])
            ->assertUnprocessable();

        $open = User::factory()->create([
            'username' => 'open',
            'contact_requests_policy' => ContactRequestPolicy::Everyone,
        ]);

        $this
            ->actingAs($actor)
            ->postJson('/api/v1/contact-requests', ['username' => 'open'])
            ->assertCreated();

        $this->assertDatabaseMissing('contact_requests', [
            'sender_id' => $actor->id,
            'receiver_id' => $closed->id,
        ]);

        $this->assertDatabaseMissing('contact_requests', [
            'sender_id' => $actor->id,
            'receiver_id' => $contactsOnly->id,
        ]);
    }
}
