<?php

namespace App\Console\Commands;

use App\Jobs\RetryGlpiTicketCreation;
use Illuminate\Console\Command;

class RetryGlpiTicketsCommand extends Command
{
    protected $signature = 'support:retry-glpi';

    protected $description = 'Retenter la création GLPI des tickets en échec';

    public function handle(): int
    {
        $this->info('Lancement du retry GLPI...');

        RetryGlpiTicketCreation::dispatchSync();

        $this->info('Terminé.');

        return self::SUCCESS;
    }
}
