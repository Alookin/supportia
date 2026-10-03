<?php

namespace App\Console\Commands;

use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Réserve des catégories à une équipe (ou les rend communes).
 *
 * Exemples :
 *   php artisan zeno:category-team marketing_emailing marketing_site --team=Marketing
 *   php artisan zeno:category-team tech_flux_bug_import --team=Commercial
 *   php artisan zeno:category-team marketing_site --team=Marketing --detach
 *   php artisan zeno:category-team --list
 *
 * Une catégorie sans équipe est proposée à tout le monde.
 */
class CategoryTeamCommand extends Command
{
    protected $signature = 'zeno:category-team
                            {slugs?* : Slugs des catégories}
                            {--team= : Équipe (créée si absente)}
                            {--org=via-mobilis : Slug de l\'organisation}
                            {--detach : Retire l\'équipe au lieu de l\'ajouter}
                            {--list : Affiche les catégories et leurs équipes}';

    protected $description = 'Rattache des catégories à une équipe (catégories propres au marketing, aux commerciaux…)';

    public function handle(): int
    {
        $org = Organization::where('slug', $this->option('org'))->first();
        if (! $org) {
            $this->error("Organisation introuvable : {$this->option('org')}");

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->table(
                ['slug', 'libellé', 'GLPI', 'visible', 'équipes'],
                GlpiCategoryMap::where('organization_id', $org->id)->with('teams')->orderBy('slug')->get()
                    ->map(fn ($c) => [
                        $c->slug,
                        $c->label_simple ?: $c->label,
                        $c->glpi_category_id,
                        $c->is_visible_to_users ? 'oui' : 'IA',
                        $c->teams->pluck('name')->implode(', ') ?: '(toutes)',
                    ])->all()
            );

            return self::SUCCESS;
        }

        $slugs = $this->argument('slugs');
        if (! $slugs || ! $this->option('team')) {
            $this->error('Préciser au moins un slug et --team=... (ou --list).');

            return self::FAILURE;
        }

        $team       = CreateUserCommand::resolveTeam($org, $this->option('team'));
        $categories = GlpiCategoryMap::where('organization_id', $org->id)->whereIn('slug', $slugs)->get();

        $missing = array_diff($slugs, $categories->pluck('slug')->all());
        if ($missing) {
            $this->error('Catégories introuvables : ' . implode(', ', $missing));

            return self::FAILURE;
        }

        foreach ($categories as $category) {
            $this->option('detach')
                ? $category->teams()->detach($team->id)
                : $category->teams()->syncWithoutDetaching([$team->id]);
        }

        $this->info(($this->option('detach') ? 'Retirées de' : 'Rattachées à') . " l'équipe {$team->name} : " . implode(', ', $slugs));

        return self::SUCCESS;
    }
}
