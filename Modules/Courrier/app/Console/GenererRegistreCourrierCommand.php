<?php

namespace Modules\Courrier\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Contracts\RegistreCourrierPdfGenerator;

/**
 * Produit le registre imprimable d'une période — document que le
 * Secrétariat Général signe et que la tutelle réclame en cas de contrôle.
 */
class GenererRegistreCourrierCommand extends Command
{
    protected $signature = 'courrier:registre {debut} {fin} {--type=arrivee : arrivee|depart}';

    protected $description = "Génère le registre PDF (arrivée ou départ) d'une période donnée";

    public function __construct(private readonly RegistreCourrierPdfGenerator $generateur)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = $this->option('type');
        if (! in_array($type, ['arrivee', 'depart'], true)) {
            $this->error('Le type doit être "arrivee" ou "depart".');

            return self::FAILURE;
        }

        $debut = Carbon::parse($this->argument('debut'))->startOfDay();
        $fin = Carbon::parse($this->argument('fin'))->endOfDay();

        $pdf = $this->generateur->generer($type, $debut, $fin);
        $chemin = sprintf(
            'registres/registre-%s-%s-au-%s.pdf',
            $type,
            $debut->toDateString(),
            $fin->toDateString(),
        );
        Storage::disk('local')->put($chemin, $pdf);

        $this->info("Registre généré : {$chemin}");

        return self::SUCCESS;
    }
}
