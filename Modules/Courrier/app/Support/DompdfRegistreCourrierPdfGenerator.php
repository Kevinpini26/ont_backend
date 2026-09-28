<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Carbon;
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
        $colonneNumero = $type === 'depart' ? 'numero_depart' : 'numero_accuse_reception';

        $user = auth()->user();
        $poste = $user === null ? null : (app(DelegationResolver::class)->posteDelegueAujourdhui($user) ?? $user->poste);
        $courriers = Courrier::query()
            ->when($poste !== Poste::SECRETARIAT_2, fn ($query) => $query->withoutGlobalScopes())
            ->whereNotNull($colonneNumero)
            ->whereBetween($type === 'depart' ? 'date_envoi' : 'created_at', [$debut, $fin])
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
