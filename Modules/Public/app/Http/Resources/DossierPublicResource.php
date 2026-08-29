<?php

namespace Modules\Public\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Stagiaires\Models\Stagiaire;

/** @mixin Courrier */
class DossierPublicResource extends JsonResource
{
    public function __construct($courrier, private readonly ?Stagiaire $stagiaire = null)
    {
        parent::__construct($courrier);
    }

    /**
     * Vue volontairement restreinte : un candidat externe ne doit voir que
     * la progression de son dossier, jamais les annotations internes, avis
     * de la DG, note technique ou identité des agents traitants — et jamais
     * le statut interne du circuit (huit étapes, Protocole/DGA/DG...) quel
     * que soit le type de courrier, pas seulement une demande de stage :
     * seul un statut simplifié l'est.
     */
    public function toArray(Request $request): array
    {
        $estDemandeStage = $this->type?->value === 'demande_stage';

        return [
            'numero_accuse_reception' => $this->numero_accuse_reception,
            'objet' => $this->objet,
            'type' => $this->type?->value,
            'statut_simplifie' => $estDemandeStage ? $this->statutSimplifieDemandeStage() : $this->statutSimplifieCourrier(),
            'date_reception' => $this->created_at?->toDateString(),
            'stagiaire' => (! $estDemandeStage && $this->stagiaire) ? [
                'statut' => $this->stagiaire->statut?->value,
                'statut_label' => $this->stagiaire->statut?->label(),
                'date_debut_stage' => $this->stagiaire->date_debut_stage?->toDateString(),
                'date_fin_stage' => $this->stagiaire->date_fin_stage?->toDateString(),
            ] : null,
        ];
    }

    private function statutSimplifieDemandeStage(): string
    {
        return match ($this->avis_dg?->value) {
            'favorable' => 'Favorable, transmis au service des stages',
            'defavorable' => 'Non retenu',
            default => "En cours d'examen",
        };
    }

    /**
     * Un courrier externe (correspondance générale déposée via
     * /depot-courrier-externe) n'a que deux états pertinents pour son
     * expéditeur : encore en circulation interne, ou définitivement traité
     * et enregistré — les étapes intermédiaires (Protocole, avis DG, projet
     * de réponse, relecture, signature) sont un détail de fonctionnement
     * interne à l'ONT, jamais candidat à l'exposition publique.
     */
    private function statutSimplifieCourrier(): string
    {
        return $this->statut === CourrierStatut::ENREGISTRE ? 'Traité' : 'En cours de traitement';
    }
}
