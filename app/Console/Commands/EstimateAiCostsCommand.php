<?php

namespace App\Console\Commands;

use App\Models\AiRequestLog;
use App\Services\AiPricing;
use Illuminate\Console\Command;

/**
 * Complète le coût estimé des appels IA déjà enregistrés, d'après les tarifs de config/supportia.php.
 * À lancer après avoir renseigné ou corrigé un tarif. Par défaut, seuls les coûts manquants sont calculés ;
 * --all recalcule aussi ceux déjà présents (l'historique prend alors les tarifs actuels).
 */
class EstimateAiCostsCommand extends Command
{
    protected $signature = 'zeno:estimate-ai-costs {--all : Recalculer aussi les coûts déjà estimés}';

    protected $description = 'Calcule le coût estimé des appels IA à partir des tarifs configurés';

    public function handle(): int
    {
        $updated = 0;
        $skipped = 0;

        AiRequestLog::whereNotNull('prompt_tokens')
            ->whereNotNull('completion_tokens')
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('estimated_cost'))
            ->chunkById(500, function ($logs) use (&$updated, &$skipped) {
                foreach ($logs as $log) {
                    $cost = AiPricing::estimate($log->model, $log->prompt_tokens, $log->completion_tokens, $log->cached_tokens);

                    if ($cost === null) {
                        $skipped++;
                        continue;
                    }

                    $log->update(['estimated_cost' => $cost]);
                    $updated++;
                }
            });

        $this->info("{$updated} appel(s) chiffré(s), {$skipped} sans tarif renseigné pour leur modèle.");

        return self::SUCCESS;
    }
}
