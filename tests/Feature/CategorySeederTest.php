<?php

namespace Tests\Feature;

use App\Models\GlpiCategoryMap;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garde-fou : chaque catégorie active du seeder pointe vers une catégorie
 * qui existe réellement dans GLPI Via-Mobilis (export du 02/10/2026).
 * Si GLPI change, mettre à jour GLPI_CATEGORY_IDS et le seeder ensemble.
 */
class CategorySeederTest extends TestCase
{
    use RefreshDatabase;

    /** IDs ITILCategory présents dans GLPI le 02/10/2026 (0 = sans catégorie, tri manuel). */
    private const GLPI_CATEGORY_IDS = [
        0, 2, 3, 6, 7, 8, 9, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25,
        26, 27, 28, 29, 30, 31, 33, 34, 35, 36, 37, 38, 39, 40,
    ];

    public function test_active_categories_point_to_existing_glpi_categories(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class); // relançable sans doublon

        $this->assertSame(32, GlpiCategoryMap::count());

        foreach (GlpiCategoryMap::where('is_active', true)->pluck('glpi_category_id', 'slug') as $slug => $id) {
            $this->assertContains($id, self::GLPI_CATEGORY_IDS, "Catégorie {$slug} → ID GLPI {$id} inexistant");
        }

        $this->assertSame(35, GlpiCategoryMap::where('slug', 'tech_diffusion')->value('glpi_category_id'));
        $this->assertSame(34, GlpiCategoryMap::where('slug', 'tech_photos_medias')->value('glpi_category_id'));
        $this->assertFalse(GlpiCategoryMap::where('slug', 'tech_bug_back')->value('is_active'));
    }
}
