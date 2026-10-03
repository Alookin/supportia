<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Change l'équipe et/ou le rôle d'un compte existant.
 *
 * Exemples :
 *   php artisan zeno:user-update jean.dupont@via-mobilis.com --team=Commercial
 *   php artisan zeno:user-update chef.ventes@via-mobilis.com --role=team_admin
 *   php artisan zeno:user-update nicolas@via-mobilis.com --role=admin
 *
 * Quand un utilisateur reçoit une équipe, ses anciens tickets sans équipe y sont rattachés
 * (ceux déjà rattachés à une autre équipe ne bougent pas).
 */
class UpdateUserCommand extends Command
{
    protected $signature = 'zeno:user-update
                            {email : Email du compte}
                            {--team= : Nouvelle équipe (créée si absente) ; "none" pour retirer}
                            {--role= : member | team_admin | admin}';

    protected $description = 'Modifie l\'équipe et/ou le rôle d\'un utilisateur Zeno';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();

        if (! $user) {
            $this->error('Utilisateur introuvable : ' . $this->argument('email'));

            return self::FAILURE;
        }

        if ($this->option('team') === null && $this->option('role') === null) {
            $this->error('Rien à modifier : préciser --team et/ou --role.');

            return self::FAILURE;
        }

        if ($this->option('role') !== null) {
            $role = UserRole::tryFrom((string) $this->option('role'));
            if (! $role) {
                $this->error('Rôle invalide : ' . $this->option('role') . ' (member | team_admin | admin)');

                return self::FAILURE;
            }
            $user->role = $role;
        }

        if ($this->option('team') !== null) {
            $user->team_id = strtolower((string) $this->option('team')) === 'none'
                ? null
                : CreateUserCommand::resolveTeam($user->organization, $this->option('team'))?->id;
        }

        if ($user->role === UserRole::TeamAdmin && ! $user->team_id) {
            $this->error('Un team_admin doit avoir une équipe (--team=...).');

            return self::FAILURE;
        }

        $user->save();

        $backfilled = 0;
        if ($user->team_id) {
            $backfilled = SupportTicket::where('user_id', $user->id)
                ->whereNull('team_id')
                ->update(['team_id' => $user->team_id]);
        }

        $user->load('team');
        $this->info("{$user->name} <{$user->email}> — équipe : " . ($user->team?->name ?? 'aucune')
            . ' — rôle : ' . ($user->role?->label() ?? 'Membre'));

        if ($backfilled) {
            $this->line("{$backfilled} ticket(s) existant(s) rattaché(s) à l'équipe.");
        }

        return self::SUCCESS;
    }
}
