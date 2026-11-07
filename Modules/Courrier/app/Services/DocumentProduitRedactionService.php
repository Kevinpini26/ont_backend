<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DecisionDirection;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Support\ReferenceDocumentaire;
use Modules\Courrier\Support\TipTapHtmlRenderer;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use RuntimeException;
use Throwable;

/**
 * Rédaction, correction et validation d'un document produit par une
 * direction (depuis un traitement, ou autonome) — jusqu'à l'attribution de
 * sa référence documentaire et la génération de son PDF officiel. La
 * réception du document validé (circuit normal, autonome) relève de
 * DocumentProduitReceptionService ; la consultation du PDF déjà généré, de
 * DocumentProduitPdfOfficielService.
 */
class DocumentProduitRedactionService
{
    public function __construct(
        private readonly ReferenceDocumentInterneService $references,
        private readonly DocumentRelationService $relations,
        private readonly AuditLogger $audit,
        private readonly DossierWorkflowGuard $dossiers,
        private readonly DirecteurDirectionResolver $directeurs,
        private readonly PdfGenerationService $pdf,
        private readonly TipTapHtmlRenderer $renduTipTap,
        private readonly DocumentProduitDirectionHelpers $helpers,
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

            return $this->helpers->charger($processus);
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

            // Direction destinataire souhaitée : facultative (la DG décide en dernier ressort).
            $destinationId = isset($donnees['direction_destination_souhaitee_id']) ? (int) $donnees['direction_destination_souhaitee_id'] : null;
            if ($destinationId !== null
                && ($destinationId === $direction->id
                    || ! Direction::query()->whereKey($destinationId)->where('actif', true)->where('est_operationnelle', true)->exists())) {
                throw ValidationException::withMessages([
                    'direction_destination_souhaitee_id' => 'Choisissez une autre direction, active et opérationnelle, comme destinataire.',
                ]);
            }

            $courrier = Courrier::sansDossierInitial(fn () => Courrier::withoutGlobalScopes()->create([
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
            ]));
            $document = DocumentProduitDirection::query()->create([
                'origine' => DocumentProduitOrigine::AUTONOME,
                'traitement_direction_id' => null,
                'courrier_id' => $courrier->id,
                'document_source_id' => null,
                'direction_id' => $direction->id,
                'direction_destination_souhaitee_id' => $destinationId,
                'nature' => $donnees['nature'] ?? null,
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

            return $this->helpers->charger($document);
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

            return $this->helpers->charger($document);
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

            return $this->helpers->charger($document);
        });
    }

    public function demanderCorrection(DocumentProduitDirection $document, User $directeur, string $motif): DocumentProduitDirection
    {
        return DB::transaction(function () use ($document, $directeur, $motif) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $this->verifierDirecteur($document, $directeur);
            $document->update(['statut' => DocumentProduitStatut::A_CORRIGER, 'correction_demandee_par_id' => $directeur->id, 'motif_correction' => $motif, 'correction_demandee_at' => now()]);
            $this->audit->enregistrer('document_direction.correction_demandee', $document, $directeur, ['motif' => $motif]);

            return $this->helpers->charger($document);
        });
    }

    public function valider(DocumentProduitDirection $document, User $directeur): DocumentProduitDirection
    {
        $cheminTemporaire = null;
        $cheminFinal = null;
        $transactionPretACommit = false;

        try {
            return DB::transaction(function () use ($document, $directeur, &$cheminTemporaire, &$cheminFinal, &$transactionPretACommit) {
                $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
                if ($document->origine === DocumentProduitOrigine::AUTONOME) {
                    $this->verifierDirecteur($document, $directeur);
                    $direction = Direction::query()->lockForUpdate()->findOrFail($document->direction_id);
                    $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);
                    if ($document->traitement_direction_id !== null
                        || $document->document_source_id !== null
                        || $document->directeur_id !== $directeur->id
                        || $courrier->direction_origine_id !== $document->direction_id) {
                        throw ValidationException::withMessages(['document' => 'Le document autonome et sa direction productrice sont incohérents.']);
                    }

                    if ($courrier->reference_documentaire === null) {
                        $courrier = $this->references->attribuer($courrier, $direction, $directeur);
                    } elseif ($document->transmis_reception_par_id === null
                        || $document->transmis_reception_at === null
                        || $document->correction_demandee_at === null
                        || $document->pdf_officiel_version_contenu === null
                        || $document->version_contenu <= $document->pdf_officiel_version_contenu
                        || ! ReferenceDocumentaire::estValide($courrier->reference_documentaire)) {
                        throw ValidationException::withMessages([
                            'document' => 'La référence documentaire existante ne peut pas être remplacée et cette nouvelle validation ne possède pas une correction recevable.',
                        ]);
                    }

                    if (! is_array($courrier->contenu)) {
                        throw ValidationException::withMessages([
                            'contenu' => 'Le contenu TipTap du document autonome est invalide.',
                        ]);
                    }

                    $corpsHtml = $this->renduTipTap->renderOfficial($courrier->contenu);
                    $contenuPdf = $this->pdf->genererDepuisVue('courrier::document-produit-autonome-officiel', [
                        'objet' => $courrier->objet,
                        'corpsHtml' => $corpsHtml,
                        'direction' => $direction->nom,
                        'reference' => $courrier->reference_documentaire,
                        'logoOntDataUri' => $this->logoOntDataUri(),
                    ]);
                    $empreinte = $this->validerContenuPdf($contenuPdf);
                    $cheminTemporaire = 'documents-internes/officiels/.staging/'.Str::uuid().'.pdf';
                    $cheminFinal = sprintf(
                        'documents-internes/officiels/%d/%d-%s.pdf',
                        $document->id,
                        $document->version_contenu,
                        $empreinte,
                    );
                    $this->stockerEtPromouvoirPdf($contenuPdf, $empreinte, $cheminTemporaire, $cheminFinal);

                    $document->pdf_officiel_chemin = $cheminFinal;
                    $document->pdf_officiel_sha256 = $empreinte;
                    $document->pdf_officiel_version_contenu = $document->version_contenu;
                    $document->pdf_officiel_genere_at = now();
                    $document->statut = DocumentProduitStatut::VALIDE;
                    $document->valide_par_id = $directeur->id;
                    $document->valide_at = now();
                    $document->save();
                    $this->audit->enregistrer('document_direction.valide', $document, $directeur, [
                        'reference_documentaire' => $courrier->reference_documentaire,
                        'origine' => DocumentProduitOrigine::AUTONOME->value,
                        'version_contenu' => $document->version_contenu,
                        'pdf_officiel_sha256' => $empreinte,
                    ]);

                    $transactionPretACommit = true;

                    return $this->helpers->charger($document);
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
                $this->audit->enregistrer('document_direction.valide', $document, $directeur, [
                    'reference_documentaire' => $courrier->reference_documentaire,
                ]);

                $transactionPretACommit = true;

                return $this->helpers->charger($document);
            });
        } catch (Throwable $exception) {
            if ($cheminTemporaire !== null && ! $transactionPretACommit) {
                $this->compenserFichiersPdf($cheminTemporaire, $cheminFinal);
            } elseif ($transactionPretACommit && $cheminFinal !== null) {
                Log::warning('Résultat de commit incertain pour le PDF officiel; fichier conservé pour réconciliation.', [
                    'document_id' => $document->id,
                    'chemin' => $cheminFinal,
                    'exception' => $exception,
                ]);
            }

            throw $exception;
        }
    }

    private function validerContenuPdf(string $contenu): string
    {
        if (! str_starts_with($contenu, '%PDF-') || ! str_contains($contenu, '%%EOF')) {
            throw new RuntimeException('Le moteur PDF a produit un contenu incomplet ou invalide.');
        }

        return hash('sha256', $contenu);
    }

    private function stockerEtPromouvoirPdf(string $contenu, string $empreinte, string $temporaire, string $final): void
    {
        $disque = Storage::disk('local');
        if ($disque->exists($temporaire) || $disque->exists($final)) {
            throw new RuntimeException('Un chemin de stockage PDF officiel existe déjà.');
        }
        if (! $disque->put($temporaire, $contenu)) {
            throw new RuntimeException('Impossible d’écrire le PDF officiel temporaire.');
        }

        $contenuTemporaire = $disque->get($temporaire);
        if (! hash_equals($empreinte, hash('sha256', $contenuTemporaire))
            || ! hash_equals($empreinte, hash('sha256', $contenu))) {
            throw new RuntimeException('Le PDF temporaire ne correspond pas à son empreinte SHA-256.');
        }
        if (! $disque->move($temporaire, $final)) {
            throw new RuntimeException('Impossible de promouvoir le PDF officiel vers son emplacement privé.');
        }

        $contenuFinal = $disque->get($final);
        if (! hash_equals($empreinte, hash('sha256', $contenuFinal))
            || ! str_starts_with($contenuFinal, '%PDF-')) {
            throw new RuntimeException('Le PDF promu ne correspond pas à son empreinte ou à son format.');
        }
    }

    private function compenserFichiersPdf(string $temporaire, ?string $final): void
    {
        $disque = Storage::disk('local');

        foreach (array_filter([$temporaire, $final]) as $chemin) {
            try {
                if ($disque->exists($chemin) && ! $disque->delete($chemin)) {
                    throw new RuntimeException("Impossible de supprimer le fichier PDF temporaire {$chemin}.");
                }
            } catch (Throwable $exception) {
                Log::critical('Échec de compensation du stockage PDF officiel; une réconciliation est requise.', [
                    'chemin' => $chemin,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function logoOntDataUri(): string
    {
        $logoPath = base_path('Modules/Courrier/resources/ONT.png');
        if (! is_file($logoPath)) {
            throw new RuntimeException('Le logo institutionnel requis pour le PDF officiel est absent.');
        }

        $contenu = file_get_contents($logoPath);
        if ($contenu === false) {
            throw new RuntimeException('Le logo institutionnel ne peut pas être lu.');
        }

        return 'data:image/png;base64,'.base64_encode($contenu);
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
