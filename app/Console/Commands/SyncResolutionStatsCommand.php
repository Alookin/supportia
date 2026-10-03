<?php

namespace App\Console\Commands;

use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use App\Services\GlpiClientService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Délai de résolution médian par catégorie GLPI (12 derniers mois), à partir de
 * solve_delay_stat (secondes, de la création à la résolution).
 * Sert à l'estimation affichée au commercial. Planifiée chaque nuit.
 */
class SyncResolutionStatsCommand extends Command
{
    protected $signature = 'glpi:sync-resolution-stats {--org= : Slug d\'une seule organisation} {--months=12}';

    protected $description = 'Calcule le délai de résolution médian par catégorie à partir de GLPI';

    public function handle(GlpiClientService $glpi): int
    {
        $orgs = Organization::where('is_active', true)
            ->when($this->option('org'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get()
            ->filter->hasGlpiConfig();

        $since = now()->subMonths((int) $this->option('months'))->format('Y-m-d H:i:s');

        foreach ($orgs as $org) {
            try {
                $delays = $this->collectDelays($glpi, $org, $since);
            } catch (\Throwable $e) {
                $this->error("{$org->slug} : {$e->getMessage()}");
                Log::warning('[GLPI stats] échec', ['org' => $org->slug, 'error' => $e->getMessage()]);

                continue;
            }

            $updated = 0;
            foreach (GlpiCategoryMap::where('organization_id', $org->id)->where('glpi_category_id', '>', 0)->get() as $category) {
                $values = $delays[$category->glpi_category_id] ?? [];

                $category->update([
                    'median_resolution_seconds' => $values ? self::median($values) : null,
                    'resolution_sample_count'   => count($values),
                    'resolution_stats_at'       => now(),
                ]);
                $updated++;
            }

            $this->info("{$org->slug} : " . array_sum(array_map('count', $delays)) . " tickets résolus analysés, {$updated} catégories mises à jour.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, list<int>> glpi_category_id => délais en secondes
     */
    private function collectDelays(GlpiClientService $glpi, Organization $org, string $since): array
    {
        $delays = [];
        $offset = 0;
        $total  = null;

        do {
            [$tickets, $total] = $glpi->listRange($org, 'Ticket', $offset, 1000, $total);

            foreach ($tickets as $t) {
                $category = (int) ($t['itilcategories_id'] ?? 0);
                $delay    = (int) ($t['solve_delay_stat'] ?? 0);

                if ($category > 0 && $delay > 0 && in_array((int) ($t['status'] ?? 0), [5, 6], true)
                    && ($t['date'] ?? '') >= $since) {
                    $delays[$category][] = $delay;
                }
            }

            $offset += 1000;
        } while ($offset < $total);

        return $delays;
    }

    /** @param list<int> $values */
    public static function median(array $values): int
    {
        sort($values);
        $n = count($values);

        return $n % 2
            ? $values[intdiv($n, 2)]
            : intdiv($values[$n / 2 - 1] + $values[$n / 2], 2);
    }
}
