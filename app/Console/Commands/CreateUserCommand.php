<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Création d'un compte Zeno (l'inscription publique est désactivée).
 *
 * Exemple :
 *   php artisan zeno:user-create jean.dupont@via-mobilis.com --name="Jean Dupont" --glpi-user-id=42
 *
 * Sans --password, un mot de passe aléatoire est généré et affiché une seule fois.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'zeno:user-create
                            {email : Email de connexion}
                            {--name= : Nom affiché (demandé si absent)}
                            {--org=via-mobilis : Slug de l\'organisation}
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

        $user = User::create([
            'name'            => $name,
            'email'           => $email,
            'password'        => Hash::make($password),
            'organization_id' => $org->id,
            'glpi_user_id'    => $this->option('glpi-user-id') ?: null,
        ]);

        // Compte créé par un admin : email considéré comme vérifié
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Compte créé : #{$user->id} {$user->name} <{$user->email}> — organisation {$org->name}");

        if ($generated) {
            $this->warn("Mot de passe généré (affiché une seule fois) : {$password}");
            $this->line("L'utilisateur pourra le changer depuis son profil.");
        }

        if (! $user->glpi_user_id) {
            $this->line('Sans --glpi-user-id, le demandeur GLPI sera identifié par son email.');
        }

        return self::SUCCESS;
    }
}
