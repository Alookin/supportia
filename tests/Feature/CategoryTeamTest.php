<?php

namespace Tests\Feature;

use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_team_sees_common_categories_plus_its_own(): void
    {
        $org       = Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);
        $sales     = Team::create(['organization_id' => $org->id, 'name' => 'Commercial', 'slug' => 'commercial']);
        $marketing = Team::create(['organization_id' => $org->id, 'name' => 'Marketing', 'slug' => 'marketing']);

        $cat = fn (string $slug, string $label) => GlpiCategoryMap::create([
            'organization_id' => $org->id, 'glpi_category_id' => 0, 'slug' => $slug,
            'label' => $label, 'label_simple' => $label, 'is_active' => true, 'is_visible_to_users' => true,
        ]);
        $cat('acces', 'Accès commun');
        $cat('import', 'Import commercial');
        $cat('emailing', 'Emailing marketing');

        $this->artisan('zeno:category-team', ['slugs' => ['import'], '--team' => 'Commercial'])->assertSuccessful();
        $this->artisan('zeno:category-team', ['slugs' => ['emailing'], '--team' => 'Marketing'])->assertSuccessful();

        $slugsFor = fn (?int $teamId) => $org->activeCategories()->forTeam($teamId)->orderBy('slug')->pluck('slug')->all();

        $this->assertSame(['acces', 'import'], $slugsFor($sales->id));
        $this->assertSame(['acces', 'emailing'], $slugsFor($marketing->id));
        $this->assertSame(['acces'], $slugsFor(null));

        // Le formulaire d'une marketeuse ne propose pas les catégories commerciales
        $sonia = User::factory()->create(['organization_id' => $org->id, 'team_id' => $marketing->id]);
        $this->actingAs($sonia)->get('/support')->assertOk()
            ->assertSee('Emailing marketing')->assertDontSee('Import commercial');
    }

    public function test_unknown_category_is_refused(): void
    {
        Organization::create(['name' => 'Via-Mobilis', 'slug' => 'via-mobilis', 'is_active' => true]);

        $this->artisan('zeno:category-team', ['slugs' => ['nope'], '--team' => 'Marketing'])->assertFailed();
    }
}
