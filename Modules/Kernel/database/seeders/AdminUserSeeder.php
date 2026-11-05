<?php

namespace Modules\Kernel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

class AdminUserSeeder extends Seeder
{
    /** Mot de passe connu, réservé aux environnements local et de test. */
    private const MOT_DE_PASSE_DEMONSTRATION = 'ChangeMoi#ONT2026';

    /**
     * Compte administrateur initial.
     *
     * - local et testing : compte de démonstration à mot de passe connu, recréé à chaque exécution ;
     * - ailleurs (production) : créé seulement s'il n'existe pas, jamais réinitialisé, avec le mot
     *   de passe fourni par KERNEL_ADMIN_INITIAL_PASSWORD, sinon un mot de passe aléatoire
     *   inutilisable (le premier accès passe alors par la réinitialisation). Le compte exige un
     *   changement de mot de passe à la première connexion.
     */
    public function run(): void
    {
        $attributs = [
            'name' => 'Administrateur ONT',
            'role' => UserRole::ADMINISTRATEUR,
            'poste' => null,
            'direction_id' => null,
            'email_verified_at' => now(),
        ];

        if (app()->environment(['local', 'testing'])) {
            User::query()->updateOrCreate(
                ['email' => 'admin@ont.cd'],
                $attributs + ['password' => self::MOT_DE_PASSE_DEMONSTRATION],
            );

            return;
        }

        if (User::query()->where('email', 'admin@ont.cd')->exists()) {
            return;
        }

        User::query()->create($attributs + [
            'email' => 'admin@ont.cd',
            'password' => config('kernel.admin_initial_password') ?: Str::random(40),
            'doit_changer_mot_de_passe' => true,
        ]);
    }
}
