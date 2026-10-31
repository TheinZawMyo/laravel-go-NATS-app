<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class JoinPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/join/some-token')->assertRedirect('/login');
    }

    /**
     * The page only receives the token: the preview itself is fetched from the
     * API so revoked and expired links surface the same validation messages.
     */
    public function test_authenticated_users_see_the_join_page_for_a_token(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get('/join/some-token')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Join')
                ->where('token', 'some-token'));
    }
}
