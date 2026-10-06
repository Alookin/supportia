<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use App\Notifications\TicketResolvedNotification;
use App\Services\GlpiClientService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Met à jour les tickets Zeno ouverts depuis GLPI (planifiée toutes les 10 min) :
 * statut, catégorie finale (mesure de précision de l'IA), et prévient le demandeur
 * quand son ticket est résolu.
 */
class SyncTicketStatusesCommand extends Command
{
    protected $signature = 'glpi:sync-ticket-statuses {--limit=300 : Nombre max de tickets par passage}';

    protected $description = 'Synchronise statut et catégorie des tickets ouverts depuis GLPI, notifie les résolutions';

    public function handle(GlpiClientService $glpi): int
    {
        $tickets = SupportTicket::with('organization', 'user')
            ->whereNotNull('glpi_ticket_id')
            ->where('status', TicketStatus::Created->value)
            ->orderByRaw('glpi_synced_at IS NOT NULL, glpi_synced_at')   // jamais synchronisés d'abord
            ->limit((int) $this->option('limit'))
            ->get();

        $resolved = 0;

        foreach ($tickets as $ticket) {
            if (! $ticket->organization?->hasGlpiConfig() && ! GlpiClientService::dryRun()) {
                continue;
            }

            $core = $glpi->getTicketCore($ticket->organization, (int) $ticket->glpi_ticket_id);
            if ($core === null) {
                continue; // GLPI indisponible : on réessaiera au prochain passage
            }

            $updates = [
                'glpi_status'            => $core['status'],
                'glpi_category_id_final' => $core['category_id'] ?: null,
                'glpi_synced_at'         => now(),
            ];

            if (in_array($core['status'], [5, 6], true)) {
                $updates['status'] = $core['status'] === 6 ? TicketStatus::Closed->value : TicketStatus::Resolved->value;
            }

            $ticket->update($updates);

            if (isset($updates['status']) && ! $ticket->resolved_notified_at && $ticket->user && config('supportia.notify_resolved', true)) {
                try {
                    $ticket->user->notify(new TicketResolvedNotification($ticket));
                    $ticket->update(['resolved_notified_at' => now()]);
                    $resolved++;
                } catch (\Throwable $e) {
                    Log::warning('[Sync] Notification de résolution non envoyée', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->info("{$tickets->count()} ticket(s) synchronisé(s), {$resolved} demandeur(s) prévenu(s).");

        return self::SUCCESS;
    }
}
