<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_demo_teams_and_roles_idempotently(): void
    {
        Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);

        $this->seed(DemoUsersSeeder::class);
        $this->seed(DemoUsersSeeder::class);

        $this->assertSame(8, User::count());

        $sonia = User::where('email', 'sonia@demo.zeno.test')->firstOrFail();
        $this->assertTrue($sonia->isTeamAdmin());
        $this->assertSame('marketing', $sonia->team->slug);
        $this->assertSame(6, $sonia->team->users()->count());
    }
}
