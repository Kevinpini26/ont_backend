<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Support\Carbon;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Stagiaires\Contracts\RapportAnnuelGenerator;
use Modules\Stagiaires\Enums\StagiaireTypeStage;
use Modules\Stagiaires\Models\Stagiaire;

class DompdfRapportAnnuelGenerator implements RapportAnnuelGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(int $annee): string
    {
        $debut = Carbon::create($annee, 1, 1)->startOfDay();
        $fin = Carbon::create($annee, 12, 31)->endOfDay();

        $dossiersRecus = Stagiaire::query()->withoutGlobalScopes()
            ->whereBetween('created_at', [$debut, $fin])
            ->count();

        $stagesClotures = Stagiaire::query()->withoutGlobalScopes()
            ->whereBetween('cloture_at', [$debut, $fin])
            ->get();

        $noteMoyenne = $stagesClotures->avg('note_finale');

        $parDirection = Stagiaire::query()->withoutGlobalScopes()
            ->whereBetween('cloture_at', [$debut, $fin])
            ->whereNotNull('direction_id')
            ->join('directions', 'directions.id', '=', 'stagiaires.direction_id')
            ->selectRaw('directions.nom as direction_nom, count(*) as total, avg(note_finale) as moyenne')
            ->groupBy('directions.nom')
            ->orderByDesc('total')
            ->get();

        $parTypeStage = collect(StagiaireTypeStage::cases())->map(fn (StagiaireTypeStage $type) => [
            'label' => $type->label(),
            'total' => Stagiaire::query()->withoutGlobalScopes()
                ->whereBetween('cloture_at', [$debut, $fin])
                ->where('type_stage', $type)
                ->count(),
        ]);

        return $this->pdf->genererDepuisVue('stagiaires::rapport-annuel', [
            'annee' => $annee,
            'dossiersRecus' => $dossiersRecus,
            'stagesClotures' => $stagesClotures->count(),
            'noteMoyenne' => $noteMoyenne !== null ? round((float) $noteMoyenne, 1) : null,
            'parDirection' => $parDirection,
            'parTypeStage' => $parTypeStage,
        ]);
    }
}
