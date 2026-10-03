<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_attached_to_the_organization(): void
    {
        $org = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);

        $this->artisan('zeno:user-create', [
            'email'          => 'Jean.Dupont@example.com',
            '--name'         => 'Jean Dupont',
            '--glpi-user-id' => 42,
            '--password'     => 'motdepasse-solide',
        ])->assertSuccessful();

        $user = User::where('email', 'jean.dupont@example.com')->firstOrFail();
        $this->assertSame($org->id, $user->organization_id);
        $this->assertSame(42, $user->glpi_user_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('motdepasse-solide', $user->password));
    }

    public function test_it_refuses_a_duplicate_email(): void
    {
        Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        User::factory()->create(['email' => 'jean@example.com']);

        $this->artisan('zeno:user-create', [
            'email' => 'jean@example.com', '--name' => 'Jean', '--password' => 'motdepasse-solide',
        ])->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_it_fails_on_unknown_organization(): void
    {
        $this->artisan('zeno:user-create', [
            'email' => 'jean@example.com', '--name' => 'Jean', '--org' => 'inconnue',
        ])->assertFailed();
    }

    public function test_it_creates_team_and_role(): void
    {
        $org = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);

        $this->artisan('zeno:user-create', [
            'email' => 'chef@example.com', '--name' => 'Chef', '--password' => 'motdepasse-solide',
            '--team' => 'Marketing', '--role' => 'team_admin',
        ])->assertSuccessful();

        $user = User::where('email', 'chef@example.com')->firstOrFail();
        $this->assertSame('marketing', $user->team->slug);
        $this->assertSame($org->id, $user->team->organization_id);
        $this->assertTrue($user->isTeamAdmin());
    }

    public function test_team_admin_requires_a_team(): void
    {
        Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);

        $this->artisan('zeno:user-create', [
            'email' => 'chef@example.com', '--name' => 'Chef', '--password' => 'motdepasse-solide', '--role' => 'team_admin',
        ])->assertFailed();
    }

    public function test_update_assigns_team_and_backfills_tickets(): void
    {
        $org  = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'email' => 'jean@example.com']);
        $t    = \App\Models\SupportTicket::create(['organization_id' => $org->id, 'user_id' => $user->id, 'raw_description' => 'x', 'status' => 'created']);

        $this->artisan('zeno:user-update', ['email' => 'jean@example.com', '--team' => 'Commercial', '--role' => 'admin'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->isAdmin());
        $this->assertSame('commercial', $user->team->slug);
        $this->assertSame($user->team_id, $t->fresh()->team_id);
    }
}
