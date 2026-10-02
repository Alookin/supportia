<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_ticket_form(): void
    {
        $this->get('/')->assertRedirect('/support');
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/support')->assertRedirect(route('login'));
    }

    public function test_user_of_active_organization_sees_ticket_form(): void
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)->get('/support')->assertOk()->assertSee('Signaler un problème client');
    }
}
