<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Models\User;

/**
 * Annulation (enregistrement par erreur) et mise en attente d'un courrier.
 * Un courrier n'est jamais supprimé : il reste au registre, marqué annulé,
 * avec l'auteur, la date et le motif. Tant qu'il est annulé ou en attente,
 * aucune étape du circuit ne peut le faire avancer
 * (voir CourrierCircuitHelpers::assertCourrierActif).
 */
class CourrierEtatService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function annuler(Courrier $courrier, User $acteur, string $motif): Courrier
    {
        return DB::transaction(function () use ($courrier, $acteur, $motif) {
            $courrier = Courrier::query()->lockForUpdate()->findOrFail($courrier->id);
            $courrier->update(['annule_at' => now(), 'annule_par_id' => $acteur->id, 'motif_annulation' => $motif, 'suspendu_at' => null, 'suspendu_par_id' => null, 'motif_suspension' => null]);
            $this->audit->enregistrer('courrier.annule', $courrier, $acteur, ['motif' => $motif]);

            return $courrier;
        });
    }

    /**
     * Lot 6 : un dépôt en ligne non recevable sort du circuit, mais garde son numéro d'arrivée (jamais
     * réutilisé) et reste au registre avec la mention « Non recevable ». Il est aussi annulé, pour être
     * exclu partout où un courrier annulé l'est déjà.
     */
    public function declarerNonRecevable(Courrier $courrier, User $acteur, string $motif): Courrier
    {
        return DB::transaction(function () use ($courrier, $acteur, $motif) {
            $courrier = Courrier::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($courrier->getKey());
            $courrier->update([
                'non_recevable_at' => now(), 'non_recevable_par_id' => $acteur->id, 'non_recevable_motif' => $motif,
                'annule_at' => now(), 'annule_par_id' => $acteur->id, 'motif_annulation' => 'Non recevable : '.$motif,
            ]);
            $this->audit->enregistrer('courrier.non_recevable', $courrier, $acteur, [
                'numero_enregistrement' => $courrier->numero_enregistrement, 'motif' => $motif,
            ]);

            return $courrier;
        });
    }

    public function suspendre(Courrier $courrier, User $acteur, string $motif): Courrier
    {
        return DB::transaction(function () use ($courrier, $acteur, $motif) {
            $courrier = Courrier::query()->lockForUpdate()->findOrFail($courrier->id);
            $courrier->update(['suspendu_at' => now(), 'suspendu_par_id' => $acteur->id, 'motif_suspension' => $motif]);
            $this->audit->enregistrer('courrier.suspendu', $courrier, $acteur, ['motif' => $motif]);

            return $courrier;
        });
    }

    public function reprendre(Courrier $courrier, User $acteur): Courrier
    {
        return DB::transaction(function () use ($courrier, $acteur) {
            $courrier = Courrier::query()->lockForUpdate()->findOrFail($courrier->id);
            $courrier->update(['suspendu_at' => null, 'suspendu_par_id' => null, 'motif_suspension' => null]);
            $this->audit->enregistrer('courrier.suspension_levee', $courrier, $acteur);

            return $courrier;
        });
    }
}
