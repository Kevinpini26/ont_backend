<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DecisionDirection;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class DocumentProduitDirectionService
{
    public function __construct(
        private readonly NumeroGenerator $numeros,
        private readonly ReferenceDocumentInterneService $references,
        private readonly DocumentRelationService $relations,
        private readonly AuditLogger $audit,
        private readonly DossierWorkflowGuard $dossiers,
        private readonly DirecteurDirectionResolver $directeurs,
    ) {}

    public function creer(TraitementDirection $traitement, User $secretariat, array $donnees): DocumentProduitDirection
    {
        return DB::transaction(function () use ($traitement, $secretariat, $donnees) {
            $traitement = TraitementDirection::query()->lockForUpdate()->findOrFail($traitement->id);
            $this->dossiers->assertActifPourNouvelleActivite($traitement->dossier_id);
            if ($traitement->statut !== TraitementDirectionStatut::TERMINE_DIRECTEUR || $traitement->decision_directeur !== DecisionDirection::RETOUR_A_PREPARER || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION || $secretariat->direction_id !== $traitement->direction_id) {
                throw ValidationException::withMessages(['traitement' => 'Seul un retour demandé par le Directeur peut produire un document.']);
            }
            if ($traitement->documentProduit()->exists()) {
                throw ValidationException::withMessages(['traitement' => 'Un document principal existe déjà pour ce traitement.']);
            }
            $source = Courrier::withoutGlobalScopes()->findOrFail($traitement->courrier_id);
            $courrier = Courrier::withoutGlobalScopes()->create([
                'dossier_id' => $traitement->dossier_id, 'numero_accuse_reception' => null,
                'objet' => $donnees['objet'], 'contenu' => $donnees['contenu'], 'type' => CourrierType::CORRESPONDANCE_GENERALE,
                'statut' => CourrierStatut::BROUILLON_DIRECTION, 'sens' => SensCourrier::ENTRANT,
                'direction_origine_id' => $traitement->direction_id, 'necessite_avis_dg' => true, 'initie_par_dg' => false, 'created_by' => $secretariat->id,
            ]);
            $processus = DocumentProduitDirection::query()->create(['traitement_direction_id' => $traitement->id, 'courrier_id' => $courrier->id, 'document_source_id' => $source->id, 'direction_id' => $traitement->direction_id, 'statut' => DocumentProduitStatut::BROUILLON, 'cree_par_id' => $secretariat->id, 'cree_at' => now()]);
            $this->relations->relier($courrier, $source, DocumentRelationType::PRODUIT_A_PARTIR_DE, $secretariat);
            $this->audit->enregistrer('document_direction.cree', $processus, $secretariat, ['courrier_id' => $courrier->id, 'dossier_id' => $courrier->dossier_id]);

            return $this->charger($processus);
        });
    }

    public function creerAutonome(User $secretariat, array $donnees): DocumentProduitDirection
    {
        return DB::transaction(function () use ($secretariat, $donnees) {
            if ($secretariat->role !== UserRole::SECRETARIAT_DIRECTION || $secretariat->direction_id === null) {
                throw ValidationException::withMessages(['direction' => 'Seul le secrétariat d’une direction peut créer un document interne autonome.']);
            }

            $direction = Direction::query()->lockForUpdate()->findOrFail($secretariat->direction_id);
            if (! $direction->actif || ! $direction->est_operationnelle) {
                throw ValidationException::withMessages(['direction' => 'La direction productrice doit être active et opérationnelle.']);
            }
            $directeur = $this->directeurs->resolve($direction->id);

            $courrier = Courrier::withoutGlobalScopes()->create([
                'dossier_id' => null,
                'numero_accuse_reception' => null,
                'numero_enregistrement' => null,
                'reference_documentaire' => null,
                'objet' => $donnees['objet'],
                'contenu' => $donnees['contenu'],
                'type' => CourrierType::CORRESPONDANCE_GENERALE,
                'statut' => CourrierStatut::BROUILLON_DIRECTION,
                'sens' => SensCourrier::ENTRANT,
                'direction_origine_id' => $direction->id,
                'necessite_avis_dg' => false,
                'initie_par_dg' => false,
                'created_by' => $secretariat->id,
            ]);
            $document = DocumentProduitDirection::query()->create([
                'origine' => DocumentProduitOrigine::AUTONOME,
                'traitement_direction_id' => null,
                'courrier_id' => $courrier->id,
                'document_source_id' => null,
                'direction_id' => $direction->id,
                'directeur_id' => $directeur->id,
                'version_contenu' => 1,
                'statut' => DocumentProduitStatut::BROUILLON,
                'cree_par_id' => $secretariat->id,
                'cree_at' => now(),
                'modifie_par_id' => $secretariat->id,
                'modifie_at' => now(),
            ]);
            $this->audit->enregistrer('document_direction.autonome_cree', $document, $secretariat, [
                'courrier_id' => $courrier->id,
                'dossier_id' => $courrier->dossier_id,
                'direction_id' => $direction->id,
                'directeur_id' => $directeur->id,
                'version_contenu' => 1,
            ]);

            return $this->charger($document);
        });
    }

    public function modifierBrouillon(DocumentProduitDirection $document, User $secretariat, array $donnees): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $secretariat, $donnees) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $this->verifierSecretariatAutonome($document, $secretariat, [DocumentProduitStatut::BROUILLON, DocumentProduitStatut::A_CORRIGER]);
            $this->verifierVersion($document, (int) $donnees['version_contenu']);
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);
            $avant = ['objet' => $courrier->objet, 'contenu' => $courrier->contenu];
            $courrier->update(['objet' => $donnees['objet'], 'contenu' => $donnees['contenu']]);
            $version = $document->version_contenu + 1;
            $document->update([
                'statut' => DocumentProduitStatut::BROUILLON,
                'version_contenu' => $version,
                'modifie_par_id' => $secretariat->id,
                'modifie_at' => now(),
            ]);
            $this->audit->enregistrer('document_direction.brouillon_modifie', $document, $secretariat, [
                'version_avant' => $version - 1,
                'version_apres' => $version,
                'avant' => $avant,
                'apres' => ['objet' => $courrier->objet, 'contenu' => $courrier->contenu],
            ]);

            return $this->charger($document);
        });
    }

    public function soumettre(DocumentProduitDirection $document, User $secretariat, array $donnees): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $secretariat, $donnees) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $autonome = $document->origine === DocumentProduitOrigine::AUTONOME;
            $statutsAcceptes = $autonome
                ? [DocumentProduitStatut::BROUILLON]
                : [DocumentProduitStatut::BROUILLON, DocumentProduitStatut::A_CORRIGER];
            if (! in_array($document->statut, $statutsAcceptes, true) || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION || $secretariat->direction_id !== $document->direction_id) {
                throw ValidationException::withMessages(['document' => 'Ce document ne peut pas être soumis.']);
            }
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);
            if ($autonome) {
                if (! isset($donnees['version_contenu'])) {
                    throw ValidationException::withMessages(['version_contenu' => 'La version courante est requise pour soumettre ce brouillon.']);
                }
                $this->verifierVersion($document, (int) $donnees['version_contenu']);
                $champs = array_filter(['objet' => $donnees['objet'] ?? null, 'contenu' => $donnees['contenu'] ?? null], fn ($valeur) => $valeur !== null);
                if ($champs !== []) {
                    $avant = ['objet' => $courrier->objet, 'contenu' => $courrier->contenu];
                    $courrier->update($champs);
                    $version = $document->version_contenu + 1;
                    $document->update([
                        'version_contenu' => $version,
                        'modifie_par_id' => $secretariat->id,
                        'modifie_at' => now(),
                    ]);
                    $this->audit->enregistrer('document_direction.brouillon_modifie', $document, $secretariat, [
                        'version_avant' => $version - 1,
                        'version_apres' => $version,
                        'avant' => $avant,
                        'apres' => ['objet' => $courrier->objet, 'contenu' => $courrier->contenu],
                        'soumis_avec_modification' => true,
                    ]);
                }
            } else {
                $courrier->update(array_filter(['objet' => $donnees['objet'] ?? null, 'contenu' => $donnees['contenu'] ?? null], fn ($valeur) => $valeur !== null));
            }
            $document->update(['statut' => DocumentProduitStatut::SOUMIS_DIRECTEUR, 'soumis_par_id' => $secretariat->id, 'soumis_at' => now()]);
            $this->audit->enregistrer('document_direction.soumis', $document, $secretariat);

            return $this->charger($document);
        });
    }

    public function demanderCorrection(DocumentProduitDirection $document, User $directeur, string $motif): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $directeur, $motif) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $this->verifierDirecteur($document, $directeur);
            $document->update(['statut' => DocumentProduitStatut::A_CORRIGER, 'correction_demandee_par_id' => $directeur->id, 'motif_correction' => $motif, 'correction_demandee_at' => now()]);
            $this->audit->enregistrer('document_direction.correction_demandee', $document, $directeur, ['motif' => $motif]);

            return $this->charger($document);
        });
    }

    public function valider(DocumentProduitDirection $document, User $directeur): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $directeur) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->origine === DocumentProduitOrigine::AUTONOME) {
                $this->verifierDirecteur($document, $directeur);
                $direction = Direction::query()->lockForUpdate()->findOrFail($document->direction_id);
                $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);
                if ($document->traitement_direction_id !== null
                    || $document->document_source_id !== null
                    || $document->directeur_id !== $directeur->id
                    || $courrier->direction_origine_id !== $document->direction_id
                    || $courrier->dossier_id === null
                    || $courrier->reference_documentaire !== null) {
                    throw ValidationException::withMessages(['document' => 'Le document autonome et sa direction productrice sont incohérents.']);
                }
                $courrier = $this->references->attribuer($courrier, $direction, $directeur);
                $document->update(['statut' => DocumentProduitStatut::VALIDE, 'valide_par_id' => $directeur->id, 'valide_at' => now()]);
                $this->audit->enregistrer('document_direction.valide', $document, $directeur, [
                    'reference_documentaire' => $courrier->reference_documentaire,
                    'origine' => DocumentProduitOrigine::AUTONOME->value,
                    'version_contenu' => $document->version_contenu,
                ]);

                return $this->charger($document);
            }

            $traitement = TraitementDirection::query()->lockForUpdate()->findOrFail($document->traitement_direction_id);
            if ($traitement->directeur_id !== $directeur->id) {
                throw ValidationException::withMessages(['document' => 'Seul le Directeur désigné peut statuer sur ce document.']);
            }
            $directeur = User::query()->lockForUpdate()->findOrFail($traitement->directeur_id);
            $this->verifierDirecteur($document, $directeur, $traitement);
            $direction = Direction::query()->findOrFail($traitement->direction_id);
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);
            $source = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->document_source_id);
            if ($document->direction_id !== $traitement->direction_id
                || $document->document_source_id !== $traitement->courrier_id
                || $courrier->direction_origine_id !== $traitement->direction_id
                || $courrier->dossier_id !== $traitement->dossier_id
                || $source->dossier_id !== $traitement->dossier_id) {
                throw ValidationException::withMessages(['document' => 'Le document, son traitement et la direction productrice sont incohérents.']);
            }
            $courrier = $this->references->attribuer($courrier, $direction, $directeur);
            $document->update(['statut' => DocumentProduitStatut::VALIDE, 'valide_par_id' => $directeur->id, 'valide_at' => now()]);
            $this->audit->enregistrer('document_direction.valide', $document, $directeur, ['reference_documentaire' => $courrier->reference_documentaire]);

            return $this->charger($document);
        });
    }

    public function transmettreReception(DocumentProduitDirection $document, User $secretariat): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $secretariat) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->origine === DocumentProduitOrigine::AUTONOME
                || $document->statut !== DocumentProduitStatut::VALIDE
                || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION
                || $secretariat->direction_id !== $document->direction_id) {
                throw ValidationException::withMessages(['document' => 'Seul le document validé peut être transmis à la Réception.']);
            }
            $document->update(['statut' => DocumentProduitStatut::TRANSMIS_RECEPTION, 'transmis_reception_par_id' => $secretariat->id, 'transmis_reception_at' => now()]);
            $this->audit->enregistrer('document_direction.transmis_reception', $document, $secretariat);

            return $this->charger($document);
        });
    }

    public function recevoir(DocumentProduitDirection $document, User $reception): DocumentProduitDirection
    {
        $document = DB::transaction(function () use ($document, $reception) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->origine === DocumentProduitOrigine::AUTONOME
                || $document->statut !== DocumentProduitStatut::TRANSMIS_RECEPTION
                || $reception->poste !== Poste::RECEPTION) {
                throw ValidationException::withMessages(['document' => 'Ce document ne peut pas être reçu.']);
            }
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);
            $courrier->numero_enregistrement ??= $this->numeros->genererNumeroEnregistrement();
            $courrier->statut = CourrierStatut::RECU;
            $courrier->save();
            CourrierTransition::query()->create(['courrier_id' => $courrier->id, 'statut' => CourrierStatut::RECU, 'nouveau_statut' => CourrierStatut::RECU, 'tour' => $courrier->tour, 'changed_by_id' => $reception->id, 'expediteur_poste' => Poste::RECEPTION->value, 'destinataire_poste' => Poste::SECRETARIAT_1->value, 'created_at' => now()]);
            $document->update(['statut' => DocumentProduitStatut::ENTRE_CIRCUIT, 'recu_par_id' => $reception->id, 'recu_at' => now()]);
            $this->audit->enregistrer('document_direction.recu_circuit', $document, $reception, ['numero_enregistrement' => $courrier->numero_enregistrement]);

            return $document;
        });

        return $this->charger($document->fresh());
    }

    private function verifierDirecteur(DocumentProduitDirection $document, User $directeur, ?TraitementDirection $traitement = null): void
    {
        if ($document->origine === DocumentProduitOrigine::AUTONOME) {
            $directeurDesigne = $this->directeurs->resolve($document->direction_id);
            if ($document->statut !== DocumentProduitStatut::SOUMIS_DIRECTEUR
                || $document->traitement_direction_id !== null
                || $document->document_source_id !== null
                || $document->directeur_id !== $directeur->id
                || $directeurDesigne->id !== $directeur->id
                || $document->direction_id !== $directeur->direction_id
                || ! $directeur->role->estDirecteurDirection()) {
                throw ValidationException::withMessages(['document' => 'Seul le Directeur désigné peut statuer sur ce document.']);
            }

            return;
        }

        $traitement ??= TraitementDirection::query()->findOrFail($document->traitement_direction_id);
        if ($document->statut !== DocumentProduitStatut::SOUMIS_DIRECTEUR
            || $document->traitement_direction_id !== $traitement->id
            || $traitement->directeur_id !== $directeur->id
            || $document->direction_id !== $traitement->direction_id
            || $document->direction_id !== $directeur->direction_id
            || ! $directeur->role->estDirecteurDirection()) {
            throw ValidationException::withMessages(['document' => 'Seul le Directeur désigné peut statuer sur ce document.']);
        }
    }

    private function charger(DocumentProduitDirection $document): DocumentProduitDirection
    {
        return $document->load(['courrier', 'documentSource', 'direction', 'traitement.directeur', 'directeur', 'creePar', 'validePar']);
    }

    /**
     * @param  list<DocumentProduitStatut>  $statuts
     */
    private function verifierSecretariatAutonome(DocumentProduitDirection $document, User $secretariat, array $statuts): void
    {
        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || ! in_array($document->statut, $statuts, true)
            || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION
            || $secretariat->direction_id !== $document->direction_id) {
            throw ValidationException::withMessages(['document' => 'Ce brouillon ne peut pas être modifié par ce secrétariat.']);
        }
    }

    private function verifierVersion(DocumentProduitDirection $document, int $version): void
    {
        if ($document->version_contenu !== $version) {
            abort(409, 'Le brouillon a été modifié depuis son chargement. Rechargez la version courante avant de continuer.');
        }
    }
}
