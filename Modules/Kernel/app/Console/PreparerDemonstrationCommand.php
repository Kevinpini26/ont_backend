<?php

namespace Modules\Kernel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Kernel\Models\User;

/**
 * Remet les comptes de démonstration dans un état propre avant une
 * présentation : un seul mot de passe connu, comptes déverrouillés, limiteur
 * de connexions vidé. Ne touche à aucun courrier ni à aucune donnée métier.
 * Refusée en production : elle écrase des mots de passe.
 */
class PreparerDemonstrationCommand extends Command
{
    protected $signature = 'kernel:preparer-demonstration {mot_de_passe : Mot de passe commun des comptes de démonstration}';

    protected $description = 'Prépare les comptes de démonstration (mot de passe commun, déverrouillage) sans toucher aux données métier';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Commande refusée en production : elle réinitialise les mots de passe.');

            return self::FAILURE;
        }

        $motDePasse = (string) $this->argument('mot_de_passe');
        if (mb_strlen($motDePasse) < 12) {
            $this->error('Le mot de passe doit comporter au moins 12 caractères.');

            return self::FAILURE;
        }

        $comptes = User::query()->where('email', 'not like', '%@example.test')->get();
        foreach ($comptes as $compte) {
            $compte->password = $motDePasse;
            $compte->echecs_connexion_consecutifs = 0;
            $compte->verrouille_jusqu_a = null;
            $compte->save();
        }

        Artisan::call('cache:clear');
        $this->info($comptes->count().' comptes préparés.');
        $this->line('E-mails en attente d\'envoi : '.DB::table('jobs')->count().' (lancez « php artisan queue:work » pendant la démonstration).');

        return self::SUCCESS;
    }
}
