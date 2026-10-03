<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Création d'un compte Zeno (l'inscription publique est désactivée).
 *
 * Exemple :
 *   php artisan zeno:user-create jean.dupont@via-mobilis.com --name="Jean Dupont" --team=Commercial --glpi-user-id=42
 *   php artisan zeno:user-create chef.ventes@via-mobilis.com --name="Chef Ventes" --team=Commercial --role=team_admin
 *
 * Rôles : member (ses tickets), team_admin (tickets de son équipe + dashboard), admin (tout).
 * L'équipe est créée automatiquement si elle n'existe pas encore dans l'organisation.
 *
 * Sans --password, un mot de passe aléatoire est généré et affiché une seule fois.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'zeno:user-create
                            {email : Email de connexion}
                            {--name= : Nom affiché (demandé si absent)}
                            {--org=via-mobilis : Slug de l\'organisation}
                            {--team= : Équipe (ex. Commercial, Marketing) — créée si absente}
                            {--role=member : member | team_admin | admin}
                            {--glpi-user-id= : ID de l\'utilisateur dans GLPI (demandeur des tickets)}
                            {--password= : Mot de passe (généré si absent)}';

    protected $description = 'Crée un compte utilisateur Zeno rattaché à une organisation';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));
        $name  = $this->option('name') ?: $this->ask('Nom affiché');

        $org = Organization::where('slug', $this->option('org'))->first();
        if (! $org) {
            $this->error("Organisation introuvable : {$this->option('org')}");

            return self::FAILURE;
        }

        $role = UserRole::tryFrom((string) $this->option('role'));
        if (! $role) {
            $this->error('Rôle invalide : ' . $this->option('role') . ' (member | team_admin | admin)');

            return self::FAILURE;
        }

        if ($role === UserRole::TeamAdmin && ! $this->option('team')) {
            $this->error('Un team_admin doit avoir une équipe (--team=...).');

            return self::FAILURE;
        }

        $generated = ! $this->option('password');
        $password  = $this->option('password') ?: Str::password(16, symbols: false);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'glpi_user_id' => $this->option('glpi-user-id')],
            [
                'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
                'name'         => ['required', 'string', 'max:255'],
                'password'     => ['required', 'string', 'min:12'],
                'glpi_user_id' => ['nullable', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $team = self::resolveTeam($org, $this->option('team'));

        $user = User::create([
            'name'            => $name,
            'email'           => $email,
            'password'        => Hash::make($password),
            'organization_id' => $org->id,
            'team_id'         => $team?->id,
            'role'            => $role,
            'glpi_user_id'    => $this->option('glpi-user-id') ?: null,
        ]);

        // Compte créé par un admin : email considéré comme vérifié
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Compte créé : #{$user->id} {$user->name} <{$user->email}> — {$org->name}"
            . ($team ? " / équipe {$team->name}" : '') . " — rôle {$role->label()}");

        if ($generated) {
            $this->warn("Mot de passe généré (affiché une seule fois) : {$password}");
            $this->line("L'utilisateur pourra le changer depuis son profil.");
        }

        if (! $user->glpi_user_id) {
            $this->line('Sans --glpi-user-id, le demandeur GLPI sera identifié par son email.');
        }

        return self::SUCCESS;
    }

    /**
     * Retrouve l'équipe par son slug dans l'organisation, ou la crée.
     */
    public static function resolveTeam(Organization $org, ?string $name): ?Team
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return Team::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => Str::slug($name)],
            ['name' => $name],
        );
    }
}
