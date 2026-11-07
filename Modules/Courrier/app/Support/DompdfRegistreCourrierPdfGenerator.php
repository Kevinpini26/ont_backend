<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Contracts\RegistreCourrierPdfGenerator;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Setting;
use Modules\Kernel\Support\DelegationResolver;

class DompdfRegistreCourrierPdfGenerator implements RegistreCourrierPdfGenerator
{
    public function __construct(
        private readonly PdfGenerationService $pdf,
        private readonly DetecteurRupturesSequence $detecteur,
    ) {}

    public function generer(string $type, Carbon $debut, Carbon $fin, ?string $origine = null): string
    {
        // Le registre départ porte sur les sorties effectives de la période,
        // sans attribuer ou modifier les numéros établis à la signature.
        // Lot 6 : le registre d'arrivée liste TOUS les courriers entrants par leur numéro d'arrivée unique,
        // dès le dépôt (en ligne compris), annulés et non recevables compris, sans le tableau de répartition.
        // Date d'arrivée : celle du dépôt ; un ancien dépôt en ligne (accusé AR-) a reçu son numéro à sa
        // vérification par la Réception.
        $colonneNumero = $type === 'depart' ? 'numero_depart' : 'numero_enregistrement';
        $colonneDate = $type === 'depart'
            ? 'date_envoi'
            : DB::raw('(case when numero_accuse_reception is not null then coalesce(enregistre_at, created_at) else created_at end)');

        $user = auth()->user();
        $poste = $user === null ? null : (app(DelegationResolver::class)->posteDelegueAujourdhui($user) ?? $user->poste);
        $courriers = Courrier::query()
            ->when($poste !== Poste::SECRETARIAT_2, fn ($query) => $query->withoutGlobalScopes())
            ->exclureAutonomesAvantTransmission()
            ->whereNotNull($colonneNumero)
            ->whereBetween($colonneDate, [$debut, $fin])
            ->when($type !== 'depart', fn ($query) => $query->where('type', '!=', CourrierType::TABLEAU_REPARTITION->value))
            ->when($type !== 'depart' && $origine !== null, fn ($query) => $query->origineArrivee($origine))
            ->with(['directionOrigine', 'directionDestination', 'imputations.direction'])
            // Tri sur la valeur numérique (année, puis séquence), jamais sur le texte : 2026-9999 avant 2026-10000.
            ->orderByRaw("nullif(regexp_replace(split_part({$colonneNumero}, '-', 1), '\\D', '', 'g'), '')::int")
            ->orderByRaw("nullif(regexp_replace(split_part({$colonneNumero}, '-', 2), '\\D', '', 'g'), '')::bigint")
            ->get();

        $elements = $courriers->map(fn (Courrier $courrier) => (object) [
            'id' => (int) $courrier->getKey(), $colonneNumero => $courrier->getAttribute($colonneNumero),
        ]);
        // Filtré par origine, le registre a des trous attendus : la détection des ruptures n'a alors pas de sens.
        $ruptures = $origine === null ? $this->detecteur->detecter($elements, $colonneNumero) : [];

        return $this->pdf->genererDepuisVue('courrier::registre', [
            'type' => $type,
            'origine' => $origine,
            'debut' => $debut,
            'fin' => $fin,
            'courriers' => $courriers,
            'ruptures' => $ruptures,
            'total' => $courriers->count(),
            'signataire' => [
                'intitule' => (string) Setting::valeur("registre.{$type}.signataire_intitule", 'Le Secrétariat 02'),
                'poste' => Poste::tryFrom((string) Setting::valeur("registre.{$type}.signataire_poste", 'secretariat_2'))?->label(),
            ],
            // Objet des courriers confidentiels masqué sauf pour la DG et SEC2 (matrice d'accès, E).
            'objetsMasques' => $courriers
                ->reject(fn (Courrier $courrier) => $user !== null && Gate::forUser($user)->allows('voirObjetConfidentielEnMasse', $courrier))
                ->map(fn (Courrier $courrier) => (int) $courrier->getKey())->values()->all(),
        ]);
    }
}
