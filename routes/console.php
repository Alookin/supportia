<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Traitement de la file d'attente (envois GLPI en échec, relancés par le job CreateGlpiTicket).
// En production, une seule entrée cron suffit : `* * * * * php artisan schedule:run`
// (pas besoin de superviseur : le worker s'arrête quand la file est vide).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=5')
    ->everyMinute()
    ->withoutOverlapping();

// Statuts GLPI des tickets ouverts + notification « ticket résolu » au demandeur
Schedule::command('glpi:sync-ticket-statuses')->everyTenMinutes()->withoutOverlapping();

// Délai de résolution médian par catégorie (estimation affichée au commercial)
Schedule::command('glpi:sync-resolution-stats')->dailyAt('03:17')->withoutOverlapping();

// Brouillons « à valider » abandonnés (jamais confirmés par le commercial)
Schedule::command('zeno:prune-drafts')->dailyAt('02:41');

// Contenu des logs IA au-delà du délai de rétention (les compteurs restent)
Schedule::command('zeno:prune-ai-log-content')->dailyAt('02:51');
