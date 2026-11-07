<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Setting;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\CalendrierOuvre;
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
    /** Délai par défaut avant « en retard », en heures ouvrées (réglage courrier.retard_delai_heures). */
    public const HEURES_AVANT_RETARD = 14;

    private const RANG_URGENCE = ['tres_urgent' => 0, 'urgent' => 1, 'normal' => 2];

    /** Ce que le poste doit faire, une fois la réception accusée. */
    private const ACTION_PAR_STATUT = [
        'recu' => 'Transmettre au tri',
        'en_attente_tri' => 'Trier',
        'en_attente_classeur' => 'Examiner au classeur',
        'en_attente_avis_dg' => 'Décider',
        'en_attente_validation_dg' => 'Valider',
        'projet_a_rediger' => 'Rédiger le projet',
        'projet_a_valider' => 'Valider pour signature',
        'en_attente_signature' => 'Signer',
        'en_dispatch' => 'Exécuter la décision de la DG',
        'signe' => 'Envoyer le courrier',
    ];

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
            ->merge($this->documentsInternes($user))
            ->merge($this->depotsEnLigne($user))
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
        $enRetard = $extra['en_retard'] ?? $this->calendrier()->estEnRetard($depuis, $this->heuresAvantRetard());
        unset($extra['en_retard']);

        // array_merge et non « + » : les valeurs fournies (référence, objet d'une demande de stage) priment.
        return array_merge([
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
        ], $extra);
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
                ->with(['transitions', 'documentProduitDirection'])
                ->limit(200)
                ->get();

            foreach ($courriers as $courrier) {
                /** @var Courrier $courrier */
                $bordereau = $courrier->bordereauCourant();
                $aAccuser = $bordereau !== null && $bordereau->accuse_reception_at === null;
                // Un dépôt en ligne se traite d'abord à la Réception : voir depotsEnLigne().
                if ($poste === Poste::RECEPTION && $courrier->mode_reception?->value === 'depot_en_ligne' && $courrier->statut === CourrierStatut::RECU) {
                    continue;
                }
                $actions->push($this->element(
                    'file',
                    $aAccuser ? 'Accuser réception' : (self::ACTION_PAR_STATUT[$courrier->statut?->value] ?? 'Traiter'),
                    $courrier,
                    $bordereau?->created_at ?? $courrier->created_at,
                    $this->lienDeFile($courrier, $poste),
                    ['cle' => $poste->value],
                ));
            }
        }

        // Pendant un intérim, un même courrier est dans la file du DGA et dans celle de la DG : une seule
        // ligne, avec le lien de l'autorité DG (dernier poste parcouru).
        return $actions->reverse()->unique('courrier_id')->reverse()->values();
    }

    /** Un document interne autonome se traite sur l'écran dédié de SEC1 ou de la DG, pas sur la fiche. */
    private function lienDeFile(Courrier $courrier, Poste $poste): string
    {
        if ($courrier->documentProduitDirection?->origine === DocumentProduitOrigine::AUTONOME) {
            return match ($poste) {
                Poste::SECRETARIAT_1 => '/circuit/documents-autonomes-sec1',
                Poste::DG => '/circuit/documents-autonomes-dg',
                default => '/courriers/'.$courrier->id,
            };
        }

        return '/courriers/'.$courrier->id;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function depotsEnLigne(User $user): Collection
    {
        if (! $this->delegations->utilisateurHabilite($user, [Poste::RECEPTION])) {
            return collect();
        }

        return Courrier::query()
            ->where('statut', CourrierStatut::RECU->value)
            ->where('mode_reception', 'depot_en_ligne')
            ->whereNull('annule_at')
            ->whereNull('non_recevable_at')
            ->whereDoesntHave('transitions', fn ($t) => $t->where('destinataire_poste', Poste::SECRETARIAT_1->value))
            ->limit(200)
            ->get()
            ->map(fn (Courrier $c) => $this->element(
                'depot',
                $c->enregistre_at === null ? 'Enregistrer le dépôt' : 'Transmettre à SEC1',
                $c,
                $c->created_at,
                '/courriers/'.$c->id,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function documentsInternes(User $user): Collection
    {
        $requete = DocumentProduitDirection::query()->where('origine', DocumentProduitOrigine::AUTONOME->value)->with('courrier');
        $lien = fn (DocumentProduitDirection $d) => '/direction/documents-internes/'.$d->id;

        if ($user->role === UserRole::SECRETARIAT_DIRECTION && $user->direction_id !== null) {
            $actions = [
                DocumentProduitStatut::BROUILLON->value => 'Soumettre le document au Directeur',
                DocumentProduitStatut::A_CORRIGER->value => 'Corriger le document',
                DocumentProduitStatut::VALIDE->value => 'Enregistrer le scan officiel',
                DocumentProduitStatut::SCAN_ENREGISTRE->value => 'Transmettre le document à la Réception',
            ];
            $requete->where('direction_id', $user->direction_id)->whereIn('statut', array_keys($actions));
        } elseif ($user->role->estDirecteurDirection() && $user->direction_id !== null) {
            $actions = [DocumentProduitStatut::SOUMIS_DIRECTEUR->value => 'Examiner le document interne'];
            $requete->where('direction_id', $user->direction_id)->whereIn('statut', array_keys($actions));
            $lien = fn (DocumentProduitDirection $d) => '/direction/directeur/documents-internes/'.$d->id;
        } elseif ($this->delegations->utilisateurHabilite($user, [Poste::RECEPTION])) {
            $actions = [DocumentProduitStatut::TRANSMIS_RECEPTION->value => 'Contrôler le document interne'];
            $requete->whereIn('statut', array_keys($actions));
            $lien = fn () => '/circuit/documents-internes';
        } else {
            return collect();
        }

        return $requete->limit(200)->get()->map(fn (DocumentProduitDirection $d) => $this->element(
            'document_interne',
            $actions[$d->statut->value],
            $d->courrier,
            $d->soumis_at ?? $d->updated_at,
            $lien($d),
            ['cle' => (string) $d->id],
        ));
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
                    'distribution', 'Accuser réception du courrier distribué', $d->courrier, $d->execute_at, '/direction/courriers-recus',
                    ['cle' => (string) $d->id, 'en_retard' => $d->estEnRetard() || $this->calendrier()->estEnRetard($d->execute_at, $this->heuresAvantRetard())],
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
                // Le secrétariat transmet depuis « Courriers reçus » ; le directeur traite depuis « Courriers à traiter ».
                $t->statut === TraitementDirectionStatut::RECU_SECRETARIAT ? '/direction/courriers-recus' : '/direction/courriers-a-traiter',
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

    private ?CalendrierOuvre $calendrierOuvre = null;

    private function calendrier(): CalendrierOuvre
    {
        return $this->calendrierOuvre ??= CalendrierOuvre::depuisReglages();
    }

    private function heuresAvantRetard(): int
    {
        return (int) Setting::valeur('courrier.retard_delai_heures', self::HEURES_AVANT_RETARD);
    }
}
