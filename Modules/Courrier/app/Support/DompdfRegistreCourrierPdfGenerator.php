<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Courrier\Contracts\RegistreCourrierPdfGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Support\DelegationResolver;

class DompdfRegistreCourrierPdfGenerator implements RegistreCourrierPdfGenerator
{
    public function __construct(
        private readonly PdfGenerationService $pdf,
        private readonly DetecteurRupturesSequence $detecteur,
    ) {}

    public function generer(string $type, Carbon $debut, Carbon $fin): string
    {
        // Le registre départ porte sur les sorties effectives de la période,
        // sans attribuer ou modifier les numéros établis à la signature.
        // Le registre d'arrivée suit le numéro d'enregistrement, attribué à tout courrier entré
        // (guichet ou dépôt en ligne enregistré), et non l'accusé en ligne que le guichet n'attribue pas.
        // Un dépôt en ligne pas encore enregistré par la Réception n'y figure pas encore.
        $colonneNumero = $type === 'depart' ? 'numero_depart' : 'numero_enregistrement';
        $colonneDate = $type === 'depart' ? 'date_envoi' : DB::raw('coalesce(enregistre_at, created_at)');

        $user = auth()->user();
        $poste = $user === null ? null : (app(DelegationResolver::class)->posteDelegueAujourdhui($user) ?? $user->poste);
        $courriers = Courrier::query()
            ->when($poste !== Poste::SECRETARIAT_2, fn ($query) => $query->withoutGlobalScopes())
            ->exclureAutonomesAvantTransmission()
            ->whereNotNull($colonneNumero)
            ->whereBetween($colonneDate, [$debut, $fin])
            ->with(['directionOrigine', 'directionDestination', 'imputations.direction'])
            ->orderBy($colonneNumero)
            ->get();

        $elements = $courriers->map(fn (Courrier $courrier) => (object) [
            'id' => (int) $courrier->getKey(), $colonneNumero => $courrier->getAttribute($colonneNumero),
        ]);
        $ruptures = $this->detecteur->detecter($elements, $colonneNumero);

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
