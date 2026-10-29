<?php

namespace Modules\Courrier\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierAnnotationType;
use Modules\Courrier\Enums\CourrierAnnotationVisibilite;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Courrier\Models\CourrierAnnotationLecture;
use Modules\Courrier\Notifications\AnnotationCircuitNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Notes de circuit : types, droits, correction, lecture, retrait d'un rappel
 * personnel, notification interne et audit. Une note du circuit n'est jamais
 * modifiée ni supprimée : une correction est une nouvelle note qui référence
 * l'ancienne (annule_par_id).
 */
class CourrierAnnotationService
{
    public function __construct(
        private readonly CourrierCircuitService $circuit,
        private readonly CourrierVisibilityService $visibilite,
        private readonly DgAuthorityResolver $autoriteDg,
        private readonly DelegationResolver $delegations,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** Instruction : réservée à la DG (titulaire, intérim ou délégation), au DGA et aux directeurs de direction. */
    public function peutDonnerInstruction(User $user): bool
    {
        return $this->autoriteDg->estAutorite($user)
            || $this->delegations->utilisateurHabilite($user, [Poste::DGA])
            || in_array($user->role, [UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true);
    }

    public function peutCreerType(User $user, CourrierAnnotationType $type): bool
    {
        return $type !== CourrierAnnotationType::INSTRUCTION || $this->peutDonnerInstruction($user);
    }

    /**
     * @param  array{type?: ?string, destinataire_poste?: ?string, destinataire_direction_id?: ?int}  $donnees
     *
     * @throws AuthorizationException
     */
    public function creer(Courrier $courrier, User $auteur, string $contenu, array $donnees = []): CourrierAnnotation
    {
        $type = CourrierAnnotationType::tryFrom($donnees['type'] ?? '') ?? CourrierAnnotationType::OBSERVATION;
        $this->assertPeutCreer($auteur, $type);

        $destinatairePoste = $donnees['destinataire_poste'] ?? null;
        $destinataireDirection = $donnees['destinataire_direction_id'] ?? null;

        if ($type === CourrierAnnotationType::RAPPEL && ($destinatairePoste !== null || $destinataireDirection !== null)) {
            throw ValidationException::withMessages(['type' => 'Un rappel personnel n\'a pas de destinataire.']);
        }

        $annotation = $this->circuit->ajouterAnnotation($courrier, $auteur, $contenu, [
            'type' => $type->value,
            'visibilite' => ($type === CourrierAnnotationType::RAPPEL ? CourrierAnnotationVisibilite::PERSONNELLE : CourrierAnnotationVisibilite::CIRCUIT)->value,
            'destinataire_poste' => $destinatairePoste,
            'destinataire_direction_id' => $destinataireDirection,
        ]);

        $this->notifierDestinataires($annotation, $auteur);

        return $annotation;
    }

    /**
     * Une correction crée une nouvelle note (même type, même destinataire) et marque l'ancienne comme corrigée.
     *
     * @throws AuthorizationException
     */
    public function corriger(CourrierAnnotation $ancienne, User $auteur, string $contenu): CourrierAnnotation
    {
        if ($ancienne->auteur_id !== $auteur->id) {
            throw new AuthorizationException('Seul l\'auteur d\'une note peut la corriger.');
        }
        if ($ancienne->visibilite === CourrierAnnotationVisibilite::PERSONNELLE) {
            throw new AuthorizationException('Un rappel personnel se retire, il ne se corrige pas.');
        }

        $nouvelle = DB::transaction(function () use ($ancienne, $auteur, $contenu) {
            $ancienne = CourrierAnnotation::query()->lockForUpdate()->findOrFail($ancienne->id);
            if ($ancienne->annule_par_id !== null) {
                throw ValidationException::withMessages(['annotation' => 'Cette note a déjà été corrigée.']);
            }

            $courrier = Courrier::query()->findOrFail($ancienne->courrier_id);
            $nouvelle = $this->circuit->ajouterAnnotation($courrier, $auteur, $contenu, [
                'type' => $ancienne->type->value,
                'visibilite' => $ancienne->visibilite->value,
                'destinataire_poste' => $ancienne->destinataire_poste,
                'destinataire_direction_id' => $ancienne->destinataire_direction_id,
            ]);

            $ancienne->forceFill(['annule_par_id' => $nouvelle->id])->save();
            $this->audit->enregistrer('courrier.annotation_corrigee', $courrier, $auteur, [
                'annotation_id' => $ancienne->id,
                'correction_id' => $nouvelle->id,
            ]);

            return $nouvelle;
        });

        $this->notifierDestinataires($nouvelle, $auteur);

        return $nouvelle;
    }

    /** Marque la note comme lue par l'utilisateur ; la lecture d'une instruction est tracée dans le journal d'audit. */
    public function marquerLue(CourrierAnnotation $annotation, User $lecteur): void
    {
        if ($annotation->auteur_id === $lecteur->id) {
            return;
        }

        $lecture = CourrierAnnotationLecture::query()->firstOrCreate(
            ['annotation_id' => $annotation->id, 'user_id' => $lecteur->id],
            ['lu_at' => now()],
        );

        if ($lecture->wasRecentlyCreated && $annotation->type === CourrierAnnotationType::INSTRUCTION) {
            $this->audit->enregistrer('courrier.instruction_lue', $annotation->courrier, $lecteur, ['annotation_id' => $annotation->id]);
        }
    }

    /**
     * Seul un rappel personnel peut être retiré, et seulement par son auteur.
     *
     * @throws AuthorizationException
     */
    public function retirerRappel(CourrierAnnotation $annotation, User $auteur): void
    {
        if ($annotation->type !== CourrierAnnotationType::RAPPEL || $annotation->auteur_id !== $auteur->id) {
            throw new AuthorizationException('Seul un rappel personnel peut être retiré, par son auteur.');
        }

        DB::transaction(function () use ($annotation, $auteur) {
            $courrier = $annotation->courrier;
            $annotation->delete();
            $this->audit->enregistrer('courrier.rappel_retire', $courrier, $auteur, ['annotation_id' => $annotation->id]);
        });
    }

    /**
     * Notes du circuit reproduites sur les impressions (fiche, bordereau) : jamais les rappels personnels,
     * ni le fond d'un courrier confidentiel pour qui ne peut pas en consulter le contenu.
     *
     * @return Collection<int, CourrierAnnotation>
     */
    public function notesImprimables(Courrier $courrier): Collection
    {
        return $courrier->annotations()
            ->where('visibilite', CourrierAnnotationVisibilite::CIRCUIT->value)
            ->with(['auteur', 'destinataireDirection'])
            ->orderBy('courrier_annotations.id')
            ->get();
    }

    /** Instructions du circuit qui visent cet utilisateur (par son poste ou sa direction) et qu'il n'a pas encore lues. */
    public function instructionsNonLues(User $user): Builder
    {
        $poste = ($this->delegations->posteDelegueAujourdhui($user) ?? $user->poste)?->value;

        return CourrierAnnotation::query()
            ->where('type', CourrierAnnotationType::INSTRUCTION->value)
            ->where('visibilite', CourrierAnnotationVisibilite::CIRCUIT->value)
            ->whereNull('annule_par_id')
            ->where('auteur_id', '!=', $user->id)
            ->where(function (Builder $vise) use ($user, $poste): void {
                $vise->whereRaw('1 = 0');
                if ($poste !== null) {
                    $vise->orWhere('destinataire_poste', $poste);
                }
                if ($user->direction_id !== null) {
                    $vise->orWhere('destinataire_direction_id', $user->direction_id);
                }
            })
            ->whereDoesntHave('lectures', fn (Builder $l) => $l->where('user_id', $user->id))
            ->whereHas('courrier');
    }

    /** Cette note vise-t-elle cet utilisateur (par son poste ou sa direction) ? */
    public function vise(CourrierAnnotation $annotation, User $user): bool
    {
        $poste = ($this->delegations->posteDelegueAujourdhui($user) ?? $user->poste)?->value;

        return ($annotation->destinataire_poste !== null && $annotation->destinataire_poste === $poste)
            || ($annotation->destinataire_direction_id !== null && $annotation->destinataire_direction_id === $user->direction_id);
    }

    private function assertPeutCreer(User $auteur, CourrierAnnotationType $type): void
    {
        if (! $this->peutCreerType($auteur, $type)) {
            throw new AuthorizationException('Seuls la DG, le DGA et les directeurs de direction peuvent donner une instruction.');
        }
    }

    /** Instruction ou note urgente : notification interne, sans e-mail, aux seuls destinataires qui peuvent voir le courrier. */
    private function notifierDestinataires(CourrierAnnotation $annotation, User $auteur): void
    {
        if (! in_array($annotation->type, [CourrierAnnotationType::INSTRUCTION, CourrierAnnotationType::URGENT], true)) {
            return;
        }
        if ($annotation->destinataire_poste === null && $annotation->destinataire_direction_id === null) {
            return;
        }

        $annotation->loadMissing('courrier');
        $courrier = $annotation->courrier;

        User::query()
            ->where('id', '!=', $auteur->id)
            ->where(function (Builder $q) use ($annotation): void {
                $q->whereRaw('1 = 0');
                if ($annotation->destinataire_poste !== null) {
                    $q->orWhere('poste', $annotation->destinataire_poste);
                }
                if ($annotation->destinataire_direction_id !== null) {
                    $q->orWhere('direction_id', $annotation->destinataire_direction_id);
                }
            })
            ->get()
            ->filter(fn (User $destinataire) => $this->visibilite->peutVoir($destinataire, $courrier))
            ->each(fn (User $destinataire) => $this->notifications->notifier($destinataire, new AnnotationCircuitNotification($annotation)));
    }
}
