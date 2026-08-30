<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Carbon;
use Modules\Courrier\Contracts\RegistreCourrierPdfGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;

class DompdfRegistreCourrierPdfGenerator implements RegistreCourrierPdfGenerator
{
    public function __construct(
        private readonly PdfGenerationService $pdf,
        private readonly DetecteurRupturesSequence $detecteur,
    ) {}

    public function generer(string $type, Carbon $debut, Carbon $fin): string
    {
        // Le registre départ reste structurellement prévu (voir
        // NumeroGenerator::genererNumeroDepart()) mais aucun courrier
        // sortant n'existe encore comme enregistrement distinct — c'est le
        // lot 2 qui l'introduit. Un registre "depart" aujourd'hui est donc
        // toujours vide, pas une erreur : le document reste imprimable
        // (colonnes présentes, zéro ligne), plutôt que de faire échouer la
        // génération.
        $colonneNumero = $type === 'depart' ? 'numero_depart' : 'numero_accuse_reception';

        $courriers = $type === 'depart'
            ? collect()
            : Courrier::query()
                ->withoutGlobalScopes()
                ->whereNotNull($colonneNumero)
                ->whereBetween('created_at', [$debut, $fin])
                ->with(['directionOrigine', 'directionDestination', 'imputations.direction'])
                ->orderBy($colonneNumero)
                ->get();

        $ruptures = $this->detecteur->detecter($courriers, $colonneNumero);

        return $this->pdf->genererDepuisVue('courrier::registre', [
            'type' => $type,
            'debut' => $debut,
            'fin' => $fin,
            'courriers' => $courriers,
            'ruptures' => $ruptures,
            'total' => $courriers->count(),
        ]);
    }
}
