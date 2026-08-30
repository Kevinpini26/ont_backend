<?php

namespace Modules\Stagiaires\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Stagiaires\Contracts\RapportAnnuelGenerator;

/**
 * Produit le rapport annuel consolidé destiné à la tutelle — même
 * principe que Courrier\Console\GenererRegistreCourrierCommand.
 */
class GenererRapportAnnuelCommand extends Command
{
    protected $signature = 'stagiaires:rapport-annuel {annee}';

    protected $description = 'Génère le rapport annuel consolidé des stages, destiné à la tutelle';

    public function __construct(private readonly RapportAnnuelGenerator $generateur)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $annee = (int) $this->argument('annee');

        $pdf = $this->generateur->generer($annee);
        $chemin = "rapports-annuels/rapport-annuel-stagiaires-{$annee}.pdf";
        Storage::disk('local')->put($chemin, $pdf);

        $this->info("Rapport annuel généré : {$chemin}");

        return self::SUCCESS;
    }
}
