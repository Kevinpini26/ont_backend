<?php

namespace Modules\Courrier\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\DocumentProduitScanOfficiel;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\QualiteDocumentNumerise;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Exceptions\DocumentNumeriseRejeteException;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\CompteurPagesPdf;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class DocumentProduitScanOfficielService
{
    public const ATTESTATION_VERSION = 1;

    public const ATTESTATION_TEXTE_V1 = 'J\'atteste avoir personnellement vérifié que le document numérisé déposé correspond au document officiel imprimé et comporte la signature manuscrite et le cachet physiques requis.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{scan: DocumentProduitScanOfficiel, created: bool}
     */
    public function deposer(
        DocumentProduitDirection $document,
        User $deposant,
        UploadedFile $fichier,
        string $idempotencyKey,
    ): array {
        $contenuRequete = $fichier->get();
        $empreinteFichier = hash('sha256', $contenuRequete);
        $nombrePages = $this->validerPdf($contenuRequete);
        $nombrePagesPourQualite = CompteurPagesPdf::compter($contenuRequete);
        if ($nombrePagesPourQualite !== null
            && (strlen($contenuRequete) / $nombrePagesPourQualite) < config('kernel.numerisation.seuil_octets_par_page')) {
            throw DocumentNumeriseRejeteException::qualiteInsuffisante();
        }

        $idempotencyKey = strtolower($idempotencyKey);
        $empreinteRequete = $this->empreinteRequete($empreinteFichier, $deposant->id);
        $promotionCommencee = false;

        try {
            return DB::transaction(function () use (
                $document,
                $deposant,
                $contenuRequete,
                $empreinteFichier,
                $nombrePages,
                $idempotencyKey,
                $empreinteRequete,
                &$promotionCommencee,
            ): array {
                $document = DocumentProduitDirection::query()
                    ->lockForUpdate()
                    ->findOrFail($document->id);
                $this->verifierAutorisationSousVerrou($document, $deposant);

                $courrier = Courrier::withoutGlobalScopes()
                    ->lockForUpdate()
                    ->findOrFail($document->courrier_id);
                $this->verifierAssociationDocumentCourrier($document, $courrier);

                $scanParCle = DocumentProduitScanOfficiel::query()
                    ->where('document_produit_direction_id', $document->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($scanParCle !== null) {
                    if (! hash_equals($scanParCle->empreinte_requete, $empreinteRequete)) {
                        throw new ConflictHttpException('Cette clé d’idempotence a déjà été utilisée pour une autre requête.');
                    }

                    return ['scan' => $scanParCle->load('documentNumerise'), 'created' => false];
                }

                if (DocumentProduitScanOfficiel::query()
                    ->where('document_produit_direction_id', $document->id)
                    ->exists()) {
                    throw new ConflictHttpException('Un scan officiel initial est déjà enregistré pour ce document.');
                }

                $this->verifierPreconditions($document, $courrier);

                $fichierPromu = $this->promouvoirFichier(
                    $document->id,
                    $contenuRequete,
                    $empreinteFichier,
                    $promotionCommencee,
                );
                $contenuPdfStocke = $fichierPromu['contenu'];

                $dateAttestation = now();
                $documentNumerise = $courrier->numerisations()->create([
                    'version' => ((int) $courrier->numerisations()->max('version')) + 1,
                    'etape_circuit' => $courrier->statut->value,
                    'chemin' => $fichierPromu['chemin'],
                    'nombre_pages' => $nombrePages,
                    'poids_octets' => strlen($contenuPdfStocke),
                    'source' => SourceDocumentNumerise::TELEVERSEMENT,
                    'sha256' => hash('sha256', $contenuPdfStocke),
                    'qualite' => QualiteDocumentNumerise::BONNE,
                    'capture_par_id' => $deposant->id,
                ]);

                $scan = DocumentProduitScanOfficiel::query()->forceCreate([
                    'document_produit_direction_id' => $document->id,
                    'courrier_id' => $courrier->id,
                    'document_numerise_id' => $documentNumerise->id,
                    'version' => 1,
                    'idempotency_key' => $idempotencyKey,
                    'empreinte_requete' => $empreinteRequete,
                    'depose_par_id' => $deposant->id,
                    'attestation_confirmee' => true,
                    'attestation_version' => self::ATTESTATION_VERSION,
                    'atteste_at' => $dateAttestation,
                    'est_actif' => true,
                ]);

                $document->scan_officiel_document_id = $documentNumerise->id;
                $document->statut = DocumentProduitStatut::SCAN_ENREGISTRE;
                $document->save();

                $this->audit->enregistrer('document_direction.scan_officiel_depose', $document, $deposant, [
                    'document_id' => $document->id,
                    'courrier_id' => $courrier->id,
                    'depose_par_id' => $deposant->id,
                    'atteste_at' => $dateAttestation->toIso8601String(),
                    'version' => 1,
                    'sha256' => $documentNumerise->sha256,
                    'attestation_version' => self::ATTESTATION_VERSION,
                ]);

                return ['scan' => $scan->load('documentNumerise'), 'created' => true];
            }, attempts: 1);
        } catch (Throwable $exception) {
            if ($promotionCommencee) {
                Log::critical('Résultat du dépôt du scan officiel incertain; aucun fichier promu n’a été supprimé.', [
                    'document_id' => $document->id,
                    'idempotency_key' => $idempotencyKey,
                    'exception' => $exception,
                ]);

                throw new HttpException(
                    503,
                    'Le résultat du dépôt est incertain. Vérifiez-le avec la même clé d’idempotence avant toute nouvelle tentative.',
                    $exception,
                );
            }

            throw $exception;
        }
    }

    private function verifierAutorisationSousVerrou(DocumentProduitDirection $document, User $deposant): void
    {
        $deposantActuel = User::query()->findOrFail($deposant->id);

        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || ! in_array($document->statut, [DocumentProduitStatut::VALIDE, DocumentProduitStatut::SCAN_ENREGISTRE], true)
            || $deposantActuel->role !== UserRole::SECRETARIAT_DIRECTION
            || $deposantActuel->direction_id !== $document->direction_id) {
            abort(404);
        }
    }

    private function verifierAssociationDocumentCourrier(DocumentProduitDirection $document, Courrier $courrier): void
    {
        if ($document->courrier_id !== $courrier->id
            || $courrier->direction_origine_id !== $document->direction_id) {
            throw ValidationException::withMessages([
                'document' => 'Le document autonome et son Courrier parent sont incohérents.',
            ]);
        }
    }

    private function verifierPreconditions(DocumentProduitDirection $document, Courrier $courrier): void
    {
        $prefixePdf = "documents-internes/officiels/{$document->id}/";
        $cheminPdf = $document->pdf_officiel_chemin;
        $empreintePdf = $document->pdf_officiel_sha256;

        if ($document->statut !== DocumentProduitStatut::VALIDE
            || $document->traitement_direction_id !== null
            || $document->document_source_id !== null
            || $document->directeur_id === null
            || $document->valide_par_id !== $document->directeur_id
            || $document->valide_at === null
            || $document->pdf_officiel_version_contenu !== $document->version_contenu
            || ! is_string($cheminPdf)
            || ! str_starts_with($cheminPdf, $prefixePdf)
            || str_contains($cheminPdf, '..')
            || ! is_string($empreintePdf)
            || preg_match('/\A[a-f0-9]{64}\z/', $empreintePdf) !== 1
            || $courrier->statut !== CourrierStatut::BROUILLON_DIRECTION
            || $courrier->reference_documentaire === null
            || $courrier->numero_enregistrement !== null
            || $courrier->transitions()->exists()
            || $courrier->dispatchs()->exists()
            || $document->transmis_reception_par_id !== null
            || $document->transmis_reception_at !== null
            || $document->recu_par_id !== null
            || $document->recu_at !== null
            || ! Storage::disk('local')->exists($cheminPdf)) {
            throw ValidationException::withMessages([
                'document' => 'Le document ne satisfait pas les préconditions du dépôt du scan officiel.',
            ]);
        }

        $contenuPdf = Storage::disk('local')->get($cheminPdf);
        if (! is_string($contenuPdf)
            || ! hash_equals($empreintePdf, hash('sha256', $contenuPdf))
            || ! str_starts_with($contenuPdf, '%PDF-')) {
            throw ValidationException::withMessages([
                'document' => 'L’intégrité du PDF officiel ne peut pas être confirmée.',
            ]);
        }
    }

    /**
     * @return array{chemin: string, contenu: string}
     */
    private function promouvoirFichier(
        int $documentId,
        string $contenuRequete,
        string $empreinteAttendue,
        bool &$promotionCommencee,
    ): array {
        $disque = Storage::disk('local');
        $identifiant = (string) Str::uuid();
        $cheminTemporaire = "documents-internes/scans-officiels/.staging/{$identifiant}.pdf";
        $cheminFinal = "documents-internes/scans-officiels/{$documentId}/{$identifiant}.pdf";

        if ($disque->exists($cheminTemporaire) || $disque->exists($cheminFinal)) {
            throw new RuntimeException('Impossible de réserver un chemin privé unique pour le scan.');
        }

        try {
            if (! $disque->put($cheminTemporaire, $contenuRequete)) {
                throw new HttpException(503, 'Le scan ne peut pas être écrit dans le stockage privé.');
            }

            $contenuTemporaire = $disque->get($cheminTemporaire);
            if (! is_string($contenuTemporaire)
                || ! hash_equals($empreinteAttendue, hash('sha256', $contenuTemporaire))) {
                throw new HttpException(503, 'Le contrôle d’intégrité du scan en staging a échoué.');
            }
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new HttpException(503, 'Le scan ne peut pas être vérifié dans le stockage privé.', $exception);
        }

        $promotionCommencee = true;
        if (! $disque->move($cheminTemporaire, $cheminFinal)) {
            throw new HttpException(503, 'Le scan n’a pas pu être promu vers son stockage privé définitif.');
        }

        $contenuFinal = $disque->get($cheminFinal);
        if (! is_string($contenuFinal)
            || ! hash_equals($empreinteAttendue, hash('sha256', $contenuFinal))) {
            throw new HttpException(503, 'Le contrôle d’intégrité du scan promu a échoué.');
        }

        return ['chemin' => $cheminFinal, 'contenu' => $contenuFinal];
    }

    private function validerPdf(string $contenu): int
    {
        if (! str_starts_with($contenu, '%PDF-') || ! str_contains($contenu, '%%EOF')) {
            throw ValidationException::withMessages([
                'scan' => 'Le fichier doit être un PDF complet et lisible.',
            ]);
        }

        try {
            $pages = (new Parser)->parseContent($contenu)->getPages();
        } catch (\Exception) {
            throw ValidationException::withMessages([
                'scan' => 'Le fichier doit être un PDF complet et lisible.',
            ]);
        }

        if ($pages === []) {
            throw ValidationException::withMessages([
                'scan' => 'Le PDF doit contenir au moins une page lisible.',
            ]);
        }

        return count($pages);
    }

    private function empreinteRequete(string $empreinteFichier, int $deposantId): string
    {
        return hash('sha256', json_encode([
            'sha256' => $empreinteFichier,
            'depose_par_id' => $deposantId,
            'attestation_confirmee' => true,
            'attestation_version' => self::ATTESTATION_VERSION,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
