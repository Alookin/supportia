<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Relance des tickets dont l'envoi GLPI a échoué (3 tentatives max par ticket).
// En production : une entrée cron `* * * * * php artisan schedule:run`.
Schedule::command('support:retry-glpi')->everyFiveMinutes()->withoutOverlapping();
