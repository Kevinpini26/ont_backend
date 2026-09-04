<?php

namespace Modules\Kernel\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Comptes de démonstration couvrant chaque poste du circuit courrier, la
 * DFP et un responsable par direction, pour pouvoir tester le circuit
 * complet (courrier et stagiaires) sans créer chaque compte à la main
 * depuis l'interface admin. N'est jamais appelé en dehors de
 * l'environnement local — voir KernelDatabaseSeeder::run(). Mot de passe
 * unique, documenté dans le README, à ne jamais réutiliser hors
 * développement local.
 */
class DemoAccountsSeeder extends Seeder
{
    public const MOT_DE_PASSE = 'Demo#ONT2026';

    public function run(): void
    {
        // Tous les postes du circuit courrier sont "centraux" (voir
        // config('kernel.circuit_courrier_central_postes')) : ils voient les
        // dossiers de toutes les directions. La direction de rattachement
        // ci-dessous n'a donc qu'une valeur administrative, jamais une
        // portée réelle sur ce qu'ils peuvent voir ou traiter.
        $directionRattachement = Direction::query()->where('code', 'DRHL')->firstOrFail();

        foreach (Poste::cases() as $poste) {
            User::query()->updateOrCreate(
                ['email' => "{$poste->value}@ont.cd"],
                [
                    'name' => $poste->label(),
                    'password' => self::MOT_DE_PASSE,
                    'role' => UserRole::AGENT_CIRCUIT_COURRIER,
                    'poste' => $poste,
                    'direction_id' => $directionRattachement->id,
                    'email_verified_at' => now(),
                ],
            );
        }

        User::query()->updateOrCreate(
            ['email' => 'dfp@ont.cd'],
            [
                'name' => 'Agent DFP',
                'password' => self::MOT_DE_PASSE,
                'role' => UserRole::AGENT_DFP,
                'poste' => null,
                'direction_id' => null,
                'email_verified_at' => now(),
            ],
        );

        foreach (Direction::all() as $direction) {
            User::query()->updateOrCreate(
                ['email' => 'responsable.'.strtolower($direction->code).'@ont.cd'],
                [
                    'name' => 'Responsable '.$direction->code,
                    'password' => self::MOT_DE_PASSE,
                    'role' => UserRole::RESPONSABLE_DIRECTION,
                    'poste' => null,
                    'direction_id' => $direction->id,
                    'email_verified_at' => now(),
                ],
            );

            User::query()->updateOrCreate(
                ['email' => 'secretariat.'.strtolower($direction->code).'@ont.cd'],
                [
                    'name' => 'Secrétariat '.$direction->code,
                    'password' => self::MOT_DE_PASSE,
                    'role' => UserRole::SECRETARIAT_DIRECTION,
                    'poste' => null,
                    'direction_id' => $direction->id,
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
