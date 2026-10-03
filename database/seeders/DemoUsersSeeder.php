<?php

namespace Database\Seeders;

use App\Console\Commands\CreateUserCommand;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de DÉMONSTRATION (environnement local uniquement) en attendant les vraies adresses.
 * Emails en @demo.zeno.test (domaine réservé, jamais délivrable).
 *
 *   php artisan db:seed --class=DemoUsersSeeder
 *
 * Mot de passe commun : variable DEMO_PASSWORD du .env, sinon « zeno-demo-2026 ».
 * Relançable sans doublon. Refuse de tourner en production.
 *
 * ⚠ Un ticket créé avec ces comptes part dans le VRAI GLPI configuré dans l'organisation.
 */
class DemoUsersSeeder extends Seeder
{
    /** [nom, email, équipe, rôle] */
    private const USERS = [
        // Commercial (admin d'équipe réelle : Vanessa Perrin, compte existant)
        ['Léa Martin (démo)',    'lea.martin@demo.zeno.test',    'Commercial', UserRole::Member],
        ['Hugo Bernard (démo)',  'hugo.bernard@demo.zeno.test',  'Commercial', UserRole::Member],

        // Marketing — Sonia est l'admin marketing
        ['Sonia (démo)',          'sonia@demo.zeno.test',          'Marketing', UserRole::TeamAdmin],
        ['Thibaut Kouame (démo)', 'thibaut.kouame@demo.zeno.test', 'Marketing', UserRole::Member],
        ['Florent (démo)',        'florent@demo.zeno.test',        'Marketing', UserRole::Member],
        ['Julie (démo)',          'julie@demo.zeno.test',          'Marketing', UserRole::Member],
        ['Melany (démo)',         'melany@demo.zeno.test',         'Marketing', UserRole::Member],
        ['Marine (démo)',         'marine@demo.zeno.test',         'Marketing', UserRole::Member],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoUsersSeeder refusé en production.');

            return;
        }

        $org = Organization::where('slug', 'via-mobilis')->first()
            ?? Organization::where('is_active', true)->firstOrFail();

        $password = env('DEMO_PASSWORD', 'zeno-demo-2026');

        foreach (self::USERS as [$name, $email, $teamName, $role]) {
            $team = CreateUserCommand::resolveTeam($org, $teamName);

            User::updateOrCreate(
                ['email' => $email],
                [
                    'name'              => $name,
                    'password'          => Hash::make($password),
                    'organization_id'   => $org->id,
                    'team_id'           => $team->id,
                    'role'              => $role,
                    'email_verified_at' => now(),
                ]
            );
        }

        $this->command?->info(count(self::USERS) . " comptes de démo prêts ({$org->name}). Mot de passe : {$password}");
    }
}
