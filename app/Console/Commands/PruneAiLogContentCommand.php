<?php

namespace App\Console\Commands;

use App\Models\AiRequestLog;
use Illuminate\Console\Command;

/**
 * RGPD : purge le contenu des logs IA (raw_response, error) au-delà du délai de rétention.
 * Les compteurs (tokens, coût, durée, cause du fallback, attribution) et usage_raw, qui ne
 * contient que des compteurs, sont conservés. Planifiée chaque nuit.
 */
class PruneAiLogContentCommand extends Command
{
    protected $signature = 'zeno:prune-ai-log-content {--days= : Délai en jours (défaut : supportia.ai_log_content_retention_days)}';

    protected $description = 'Purge le contenu des logs IA au-delà du délai de rétention';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('supportia.ai_log_content_retention_days', 90));

        if ($days < 1) {
            $this->error('Le délai doit être d\'au moins 1 jour.');

            return self::FAILURE;
        }

        $count = AiRequestLog::where('created_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNotNull('raw_response')->orWhereNotNull('error'))
            ->update(['raw_response' => null, 'error' => null]);

        $this->info("{$count} log(s) IA purgé(s) de leur contenu (plus de {$days} jours).");

        return self::SUCCESS;
    }
}
