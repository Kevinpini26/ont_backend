<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\SequenceGenerator;
use Modules\Courrier\Models\BordereauConservation;
use Modules\Courrier\Models\Dossier;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Models\User;

/**
 * Lot 6 : fin de conservation et sort final. Aucune destruction automatique : SEC2 propose un bordereau
 * (élimination ou versement), une autre personne le valide, et la validation marque seulement le sort exécuté.
 * Aucune donnée n'est supprimée (un courrier n'est jamais supprimé, règle n° 7).
 */
class ConservationService
{
    private const SORT_PAR_TYPE = ['elimination' => 'detruire', 'versement' => 'verser'];

    public function __construct(private readonly SequenceGenerator $sequences, private readonly AuditLogger $audit) {}

    /** @return Collection<int, Dossier> dossiers archivés arrivés en fin de conservation, sort final pas encore exécuté */
    public function dossiersEchus(): Collection
    {
        return Dossier::query()
            ->with('rubriqueClassement')
            ->whereNotNull('rubrique_classement_id')
            ->whereNotNull('archive_at')
            ->whereNull('sort_final_execute_at')
            ->get()
            ->filter(fn (Dossier $d) => $d->finConservationLe() !== null && $d->finConservationLe()->lte(now())
                && in_array($d->rubriqueClassement?->sort_final, ['detruire', 'verser'], true))
            ->values();
    }

    /** @param  list<int>  $dossierIds */
    public function proposer(string $type, array $dossierIds, string $motif, User $sec2): BordereauConservation
    {
        return DB::transaction(function () use ($type, $dossierIds, $motif, $sec2) {
            $echus = $this->dossiersEchus()->keyBy('id');
            $enCours = DB::table('bordereau_conservation_dossier')
                ->join('bordereaux_conservation', 'bordereaux_conservation.id', '=', 'bordereau_conservation_dossier.bordereau_conservation_id')
                ->where('bordereaux_conservation.statut', 'propose')
                ->pluck('dossier_id')->all();
            foreach ($dossierIds as $id) {
                $dossier = $echus->get($id);
                if ($dossier === null || $dossier->rubriqueClassement?->sort_final !== self::SORT_PAR_TYPE[$type] || in_array($id, $enCours, true)) {
                    throw ValidationException::withMessages(['dossier_ids' => "Le dossier #{$id} n’est pas en fin de conservation avec ce sort final, ou figure déjà sur un bordereau."]);
                }
            }

            $prefixe = $type === 'versement' ? 'BV' : 'BE';
            $bordereau = BordereauConservation::query()->create([
                'numero' => sprintf('%s-%d-%04d', $prefixe, now()->year, $this->sequences->suivant("bordereau_{$type}", now()->year)),
                'type' => $type, 'statut' => 'propose', 'motif' => $motif,
                'propose_par_id' => $sec2->id, 'propose_at' => now(),
            ]);
            foreach ($dossierIds as $id) {
                $dossier = $echus->get($id);
                $bordereau->dossiers()->attach($id, [
                    'rubrique' => $dossier->rubriqueClassement->intitule(),
                    'fin_conservation_le' => $dossier->finConservationLe()->toDateString(),
                ]);
            }
            $this->audit->enregistrer('plan_classement.bordereau_propose', $bordereau, $sec2, ['type' => $type, 'dossier_ids' => $dossierIds, 'motif' => $motif]);

            return $bordereau->load('dossiers');
        });
    }

    public function valider(BordereauConservation $bordereau, User $validateur): BordereauConservation
    {
        return DB::transaction(function () use ($bordereau, $validateur) {
            $bordereau = BordereauConservation::query()->lockForUpdate()->findOrFail($bordereau->id);
            if ($bordereau->statut !== 'propose' || $bordereau->propose_par_id === $validateur->id) {
                throw ValidationException::withMessages(['bordereau' => 'Ce bordereau doit être validé, une seule fois, par une autre personne que son auteur.']);
            }
            $bordereau->update(['statut' => 'valide', 'valide_par_id' => $validateur->id, 'valide_at' => now()]);
            Dossier::query()->whereIn('id', $bordereau->dossiers()->pluck('dossiers.id'))
                ->update(['sort_final_bordereau_id' => $bordereau->id, 'sort_final_execute_at' => now()]);
            $this->audit->enregistrer('plan_classement.bordereau_valide', $bordereau, $validateur, [
                'numero' => $bordereau->numero, 'type' => $bordereau->type,
            ]);

            return $bordereau->load('dossiers');
        });
    }
}
