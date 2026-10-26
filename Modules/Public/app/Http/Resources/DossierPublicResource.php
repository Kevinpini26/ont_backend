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
            'etapes' => $estDemandeStage ? null : $this->etapesCourrier(),
            'date_reponse' => $estDemandeStage ? null : $this->reponseEnvoyee()?->date_envoi?->toDateString(),
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
        if ($this->reponseEnvoyee() !== null) {
            return 'Réponse envoyée';
        }

        return $this->statut === CourrierStatut::ENREGISTRE ? 'Traité' : 'En cours de traitement';
    }

    /**
     * Progression grossière destinée à l'expéditeur : quatre jalons, sans
     * jamais nommer un poste, une étape interne ou un agent.
     *
     * @return array<int, array{libelle: string, atteinte: bool}>
     */
    private function etapesCourrier(): array
    {
        $enregistre = filled($this->numero_enregistrement);
        $examen = $enregistre && ! in_array($this->statut, [CourrierStatut::RECU, CourrierStatut::EN_ATTENTE_TRI], true);
        $reponse = $this->reponseEnvoyee() !== null;

        return [
            ['libelle' => 'Reçu', 'atteinte' => true],
            ['libelle' => 'Enregistré', 'atteinte' => $enregistre],
            ['libelle' => 'En cours d’examen', 'atteinte' => $examen || $reponse],
            ['libelle' => 'Réponse envoyée', 'atteinte' => $reponse],
        ];
    }

    private function reponseEnvoyee(): ?Courrier
    {
        return Courrier::withoutGlobalScopes()
            ->where('en_reponse_a_courrier_id', $this->id)
            ->whereNotNull('date_envoi')
            ->latest('date_envoi')
            ->first();
    }
}
