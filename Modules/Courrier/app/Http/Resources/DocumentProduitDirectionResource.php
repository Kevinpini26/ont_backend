<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\User;

/** @mixin DocumentProduitDirection */
class DocumentProduitDirectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $estAutonome = $this->origine === DocumentProduitOrigine::AUTONOME;

        return [
            'id' => $this->id, 'origine' => $this->origine?->value, 'traitement_direction_id' => $this->traitement_direction_id, 'courrier_id' => $this->courrier_id,
            'document_source_id' => $this->document_source_id, 'direction_id' => $this->direction_id,
            'directeur_id' => $this->directeur_id, 'directeur' => $this->whenLoaded('directeur', fn ($directeur) => $directeur === null ? null : ['id' => $directeur->id, 'name' => $directeur->name]),
            'version_contenu' => $this->version_contenu, 'modifie_at' => $this->modifie_at,
            'statut' => $this->statut?->value, 'statut_label' => $this->statut?->label(),
            'courrier' => $this->whenLoaded('courrier', function (?Courrier $courrier) use ($request): ?array {
                if ($courrier === null) {
                    return null;
                }

                $donnees = (new CourrierResource($courrier))->resolve($request);
                if ($courrier->relationLoaded('directionOrigine')) {
                    $directionOrigine = $courrier->getRelation('directionOrigine');
                    $donnees['direction_origine'] = $directionOrigine === null
                        ? null
                        : ['id' => $directionOrigine->id, 'nom' => $directionOrigine->nom];
                }

                return $donnees;
            }),
            'document_source' => $this->whenLoaded('documentSource', fn ($source) => $source === null ? null : ['id' => $source->id, 'objet' => $source->objet, 'numero_enregistrement' => $source->numero_enregistrement]),
            'motif_correction' => $this->motif_correction, 'cree_at' => $this->cree_at, 'soumis_at' => $this->soumis_at,
            'valide_at' => $this->valide_at, 'transmis_reception_at' => $this->transmis_reception_at, 'recu_at' => $this->recu_at,
            'transmis_reception_par' => $this->whenLoaded('transmisReceptionPar', fn ($acteur) => $acteur === null ? null : ['id' => $acteur->id, 'name' => $acteur->name]),
            'correction_demandee_at' => $this->when($estAutonome, $this->correction_demandee_at),
            'tracabilite' => $this->when($estAutonome, fn () => [
                'createur' => $this->acteurCharge('creePar'),
                'dernier_editeur' => $this->acteurCharge('modifiePar'),
                'soumis_par' => $this->acteurCharge('soumisPar'),
                'correction_demandee_par' => $this->acteurCharge('correctionDemandeePar'),
                'valide_par' => $this->acteurCharge('validePar'),
            ]),
            'historique' => $this->when(
                $estAutonome
                && $this->resource instanceof DocumentProduitDirection
                && $this->resource->relationLoaded('auditLogs'),
                fn () => $this->historiqueCharge(),
            ),
        ];
    }

    private function acteurCharge(string $relation): ?array
    {
        if (! $this->resource instanceof DocumentProduitDirection || ! $this->resource->relationLoaded($relation)) {
            return null;
        }

        $acteur = $this->resource->getRelation($relation);

        return $acteur instanceof User ? ['id' => $acteur->id, 'name' => $acteur->name] : null;
    }

    /**
     * @return list<array{action: string, acteur: array{id: int, name: string}|null, date: mixed}>
     */
    private function historiqueCharge(): array
    {
        if (! $this->resource instanceof DocumentProduitDirection) {
            return [];
        }

        return $this->resource->getRelation('auditLogs')
            ->map(static function (AuditLog $audit): array {
                $acteur = $audit->relationLoaded('utilisateur') ? $audit->getRelation('utilisateur') : null;

                return [
                    'action' => $audit->action,
                    'acteur' => $acteur instanceof User ? ['id' => $acteur->id, 'name' => $acteur->name] : null,
                    'date' => $audit->created_at,
                ];
            })
            ->all();
    }
}
