<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL; // Import à ajouter tout en haut du fichier

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
{
    // On force le HTTPS si on est sur Ngrok
    if (str_contains(request()->getHost(), 'ngrok-free.dev')) {
        URL::forceScheme('https');
    }
}
}
