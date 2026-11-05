<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Accueil « Ce que j'ai à faire » (lot 3) : la liste des actions qui attendent cet agent, calculée
 * avec les mêmes règles que le circuit (file du poste, intérim, délégation, missions, relectures,
 * distributions et traitements des directions, instructions non lues). Rien n'est inventé : chaque
 * élément correspond à une action que l'API autorise déjà.
 */
class ActionsAFaireService
{
    /** Au-delà, un élément en attente est signalé en retard (même seuil que les compteurs). */
    public const HEURES_AVANT_RETARD = 48;

    private const RANG_URGENCE = ['tres_urgent' => 0, 'urgent' => 1, 'normal' => 2];

    public function __construct(
        private readonly CourrierVisibilityService $visibilite,
        private readonly DelegationResolver $delegations,
        private readonly DgAuthorityResolver $autoriteDg,
        private readonly CourrierAnnotationService $notes,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function pour(User $user): Collection
    {
        $actions = collect()
            ->merge($this->fileDuPoste($user))
            ->merge($this->missions($user))
            ->merge($this->relectures($user))
            ->merge($this->direction($user))
            ->merge($this->instructions($user));

        return $this->trier($actions);
    }

    /** @param  Collection<int, array<string, mixed>>  $actions */
    public function trier(Collection $actions): Collection
    {
        return $actions
            ->unique(fn (array $a) => $a['type'].':'.($a['courrier_id'] ?? '').':'.($a['cle'] ?? ''))
            ->sortBy([
                fn (array $a, array $b) => (self::RANG_URGENCE[$a['urgence']] ?? 3) <=> (self::RANG_URGENCE[$b['urgence']] ?? 3),
                fn (array $a, array $b) => (int) $b['en_retard'] <=> (int) $a['en_retard'],
                fn (array $a, array $b) => (string) $a['depuis'] <=> (string) $b['depuis'],
            ])
            ->values();
    }

    /** @return array<string, mixed> */
    public function element(string $type, string $action, ?Courrier $courrier, ?Carbon $depuis, string $lien, array $extra = []): array
    {
        $enRetard = $extra['en_retard'] ?? ($depuis !== null && $depuis->lt(now()->subHours(self::HEURES_AVANT_RETARD)));
        unset($extra['en_retard']);

        return [
            'type' => $type,
            'action' => $action,
            'courrier_id' => $courrier?->id,
            'reference' => $courrier === null ? null : ($courrier->numero_depart ?? $courrier->numero_enregistrement ?? $courrier->reference_documentaire ?? $courrier->numero_accuse_reception),
            'objet' => $courrier === null ? null : (Gate::allows('consulterContenu', $courrier) ? $courrier->objet : 'Objet confidentiel'),
            'statut' => $courrier?->statut?->value,
            'urgence' => $courrier?->degre_urgence?->value,
            'depuis' => $depuis?->toIso8601String(),
            'en_retard' => $enRetard,
            'lien' => $lien,
        ] + $extra;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function fileDuPoste(User $user): Collection
    {
        if ($user->role !== UserRole::AGENT_CIRCUIT_COURRIER && ! $this->autoriteDg->estAutorite($user)) {
            return collect();
        }

        $postes = array_values(array_filter([
            $this->delegations->posteDelegueAujourdhui($user) ?? $user->poste,
            $this->autoriteDg->estAutorite($user) ? Poste::DG : null,
        ]));
        $postes = array_values(array_filter(array_unique($postes, SORT_REGULAR),
            fn (Poste $p) => ! in_array($p, [Poste::ASSISTANT_1, Poste::ASSISTANT_2, Poste::ASSISTANT_DGA, Poste::PROTOCOLE, Poste::ASSISTANT_PROTOCOLE], true)));

        $actions = collect();
        foreach ($postes as $poste) {
            $courriers = $this->visibilite->restreindreAFileCourante(Courrier::query(), $poste)
                ->with('transitions')
                ->limit(200)
                ->get();

            foreach ($courriers as $courrier) {
                /** @var Courrier $courrier */
                $bordereau = $courrier->bordereauCourant();
                $aAccuser = $bordereau !== null && $bordereau->accuse_reception_at === null;
                $actions->push($this->element(
                    'file',
                    $aAccuser ? 'Accuser réception' : 'Traiter',
                    $courrier,
                    $bordereau?->created_at ?? $courrier->created_at,
                    '/courriers/'.$courrier->id,
                    ['cle' => $poste->value],
                ));
            }
        }

        return $actions;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function missions(User $user): Collection
    {
        return MissionDocumentaire::query()
            ->where('assistant_id', $user->id)
            ->whereIn('statut', [MissionDocumentaireStatut::ASSIGNEE->value, MissionDocumentaireStatut::EN_COURS->value])
            ->with('courrier')
            ->get()
            ->map(fn (MissionDocumentaire $m) => $this->element(
                'mission',
                $m->statut === MissionDocumentaireStatut::ASSIGNEE ? 'Prendre en charge la mission' : 'Poursuivre la mission',
                $m->courrier,
                $m->envoyee_at,
                '/circuit/missions',
                ['cle' => (string) $m->id],
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function relectures(User $user): Collection
    {
        return Courrier::query()
            ->where('relecteur_id', $user->id)
            ->whereIn('statut', [CourrierStatut::EN_RELECTURE->value, CourrierStatut::PROJET_A_VALIDER->value])
            ->whereNull('relecture_validee_at')
            ->get()
            ->map(fn (Courrier $c) => $this->element('relecture', 'Relire le projet', $c, $c->updated_at, '/circuit/missions?onglet=relecture'));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function direction(User $user): Collection
    {
        if (! $user->role->estDirection() || $user->direction_id === null) {
            return collect();
        }

        $actions = collect();

        if ($user->role === UserRole::SECRETARIAT_DIRECTION) {
            DispatchCourrier::query()
                ->where('direction_id', $user->direction_id)
                ->where('statut', DispatchStatut::EXECUTE->value)
                ->whereNull('accuse_reception_at')
                ->with('courrier')
                ->get()
                ->each(fn (DispatchCourrier $d) => $actions->push($this->element(
                    'distribution', 'Accuser réception du courrier distribué', $d->courrier, $d->execute_at, '/direction/courrier',
                    ['cle' => (string) $d->id, 'en_retard' => $d->estEnRetard() || ($d->execute_at?->lt(now()->subHours(self::HEURES_AVANT_RETARD)) ?? false)],
                )));
        }

        $statuts = $user->role === UserRole::SECRETARIAT_DIRECTION
            ? [TraitementDirectionStatut::RECU_SECRETARIAT->value]
            : [TraitementDirectionStatut::TRANSMIS_DIRECTEUR->value, TraitementDirectionStatut::EN_TRAITEMENT->value];

        TraitementDirection::query()
            ->where('direction_id', $user->direction_id)
            ->whereIn('statut', $statuts)
            ->with(['courrier', 'dispatch'])
            ->get()
            ->each(fn (TraitementDirection $t) => $actions->push($this->element(
                'traitement',
                $t->statut === TraitementDirectionStatut::RECU_SECRETARIAT ? 'Transmettre au directeur' : 'Traiter le courrier',
                $t->courrier,
                $t->transmis_directeur_at ?? $t->recu_secretariat_at,
                '/direction/courriers-a-traiter',
                ['cle' => (string) $t->id, 'en_retard' => $t->dispatch?->estEnRetard() ?? false],
            )));

        return $actions;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function instructions(User $user): Collection
    {
        return $this->notes->instructionsNonLues($user)
            ->with('courrier')
            ->limit(100)
            ->get()
            ->map(fn ($note) => $this->element('instruction', 'Lire l’instruction', $note->courrier, $note->created_at, '/instructions-recues', ['cle' => (string) $note->id]));
    }
}
