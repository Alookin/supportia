<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use Illuminate\Console\Command;

/**
 * Supprime les brouillons « à valider » jamais confirmés (écran de validation abandonné),
 * pièces jointes comprises. Planifiée chaque nuit.
 */
class PruneDraftsCommand extends Command
{
    protected $signature = 'zeno:prune-drafts {--hours=24}';

    protected $description = 'Supprime les brouillons de tickets non confirmés';

    public function handle(): int
    {
        $count = 0;

        SupportTicket::where('status', TicketStatus::NeedsReview->value)
            ->whereNull('glpi_ticket_id')
            ->where('created_at', '<', now()->subHours((int) $this->option('hours')))
            ->each(function (SupportTicket $ticket) use (&$count) {
                $ticket->delete();   // supprime aussi les fichiers (événement deleting)
                $count++;
            });

        $this->info("{$count} brouillon(s) supprimé(s).");

        return self::SUCCESS;
    }
}
