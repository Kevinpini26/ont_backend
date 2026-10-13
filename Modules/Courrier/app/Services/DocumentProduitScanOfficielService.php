<?php

namespace Modules\Courrier\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\DocumentProduitScanOfficiel;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\QualiteDocumentNumerise;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Exceptions\DocumentNumeriseRejeteException;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\CompteurPagesPdf;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

                $scansExistants = DocumentProduitScanOfficiel::query()
                    ->where('document_produit_direction_id', $document->id)
                    ->lockForUpdate()
                    ->orderBy('version')
                    ->get();
                $versionScan = ((int) $scansExistants->max('version')) + 1;

                if ($scansExistants->isNotEmpty()
                    && ($document->transmis_reception_par_id === null
                        || $document->transmis_reception_at === null
                        || $document->correction_demandee_at === null
                        || $document->recu_par_id !== null
                        || $document->recu_at !== null
                        || $scansExistants->where('est_actif', true)->count() !== 1
                        || (int) $scansExistants->firstWhere('est_actif', true)?->document_numerise_id !== (int) $document->scan_officiel_document_id)) {
                    throw new ConflictHttpException('Un nouveau scan ne peut être déposé qu’après une correction et une nouvelle validation du Directeur.');
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

                if ($scansExistants->isNotEmpty()) {
                    DocumentProduitScanOfficiel::query()
                        ->where('document_produit_direction_id', $document->id)
                        ->where('est_actif', true)
                        ->update(['est_actif' => false, 'updated_at' => now()]);
                }

                $scan = DocumentProduitScanOfficiel::query()->forceCreate([
                    'document_produit_direction_id' => $document->id,
                    'courrier_id' => $courrier->id,
                    'document_numerise_id' => $documentNumerise->id,
                    'version' => $versionScan,
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
                    'version' => $versionScan,
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

    public function consulter(DocumentProduitDirection $document): StreamedResponse
    {
        return $this->consulterSelonEtat($document, 'producteur');
    }

    public function consulterAccepteSec1(DocumentProduitDirection $document): StreamedResponse
    {
        return $this->consulterSelonEtat($document, 'sec1');
    }

    public function consulterEnAttenteDg(DocumentProduitDirection $document): StreamedResponse
    {
        return $this->consulterSelonEtat($document, 'dg');
    }

    public function verifierPourDg(DocumentProduitDirection $document): void
    {
        $this->consulterSelonEtat($document, 'dg');
    }

    private function consulterSelonEtat(DocumentProduitDirection $document, string $contexte): StreamedResponse
    {
        $document->refresh();

        $transmisReception = $contexte === 'reception';
        $accepteSec1 = $contexte === 'sec1';
        $attenteDg = $contexte === 'dg';
        $étatAttendu = ($accepteSec1 || $attenteDg)
            ? $document->statut === DocumentProduitStatut::ENTRE_CIRCUIT
                && $document->transmis_reception_par_id !== null
                && $document->transmis_reception_at !== null
                && $document->recu_par_id !== null
                && $document->recu_at !== null
            : ($transmisReception
                ? $document->statut === DocumentProduitStatut::TRANSMIS_RECEPTION
                && $document->transmis_reception_par_id !== null
                && $document->transmis_reception_at !== null
            : $document->statut === DocumentProduitStatut::SCAN_ENREGISTRE
                && (($document->transmis_reception_par_id === null && $document->transmis_reception_at === null)
                    || ($document->transmis_reception_par_id !== null
                        && $document->transmis_reception_at !== null
                        && $document->correction_demandee_at !== null)));

        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || ! $étatAttendu
            || ! is_numeric($document->scan_officiel_document_id)
            || $document->courrier_id === null
            || (! $accepteSec1 && ! $attenteDg && ($document->recu_par_id !== null || $document->recu_at !== null))) {
            abort(404);
        }

        $courrier = Courrier::withoutGlobalScopes()->findOrFail($document->courrier_id);
        $dispatchDg = $attenteDg ? $courrier->dispatchs()->with('direction')->first() : null;
        $dispatchCompatible = $attenteDg
            ? ($dispatchDg === null
                ? $courrier->statut === CourrierStatut::EN_ATTENTE_AVIS_DG
                : $courrier->statut === CourrierStatut::EN_DISPATCH
                    && $dispatchDg->statut === DispatchStatut::EN_ATTENTE
                    && $dispatchDg->type_destination === DispatchTypeDestination::DIRECTION
                    && $dispatchDg->dossier_id === null
                    && $dispatchDg->direction_id === $courrier->direction_destination_id)
            : ! $courrier->dispatchs()->exists();
        if ($courrier->direction_origine_id !== $document->direction_id
            || ($attenteDg
                ? ! in_array($courrier->statut, [CourrierStatut::EN_ATTENTE_AVIS_DG, CourrierStatut::EN_DISPATCH], true)
                : ($accepteSec1
                ? ! in_array($courrier->statut, [CourrierStatut::RECU, CourrierStatut::EN_ATTENTE_AVIS_DG], true)
                : $courrier->statut !== CourrierStatut::BROUILLON_DIRECTION))
            || (($transmisReception || $accepteSec1 || $attenteDg || $document->transmis_reception_at !== null) && (
                $courrier->reference_documentaire === null
                || trim($courrier->reference_documentaire) === ''
                || preg_match('/\A\d{3,}\/[A-Z]{1,12}\/[A-Z0-9]{1,20}\/ONT\/\d{4}\z/', $courrier->reference_documentaire) !== 1
            ))
            || (($accepteSec1 || $attenteDg)
                ? $courrier->numero_enregistrement === null || trim($courrier->numero_enregistrement) === ''
                : $courrier->numero_enregistrement !== null)
            || ($attenteDg
                ? ! $courrier->transitions()->where('nouveau_statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value)
                    ->where('destinataire_poste', Poste::DG->value)->exists()
                : ($accepteSec1
                ? ! $courrier->transitions()->where('nouveau_statut', CourrierStatut::RECU->value)
                    ->where('destinataire_poste', Poste::SECRETARIAT_1->value)
                    ->where('changed_by_id', $document->recu_par_id)
                    ->exists()
                : $courrier->transitions()->exists()))
            || ! $dispatchCompatible) {
            abort(404);
        }

        $étapeScanAttendue = ($accepteSec1 || $attenteDg)
            ? CourrierStatut::BROUILLON_DIRECTION->value
            : $courrier->statut->value;
        $scan = DocumentProduitScanOfficiel::query()
            ->where('document_produit_direction_id', $document->id)
            ->where('courrier_id', $courrier->id)
            ->where('est_actif', true)
            ->first();

        if ($scan === null
            || (int) $scan->version < 1
            || ! $scan->attestation_confirmee
            || $scan->atteste_at === null
            || (int) $scan->attestation_version !== self::ATTESTATION_VERSION
            || ! is_string($scan->empreinte_requete)
            || preg_match('/\A[a-f0-9]{64}\z/', $scan->empreinte_requete) !== 1
            || (int) $scan->document_numerise_id !== (int) $document->scan_officiel_document_id) {
            abort(404);
        }

        $nombreScans = DocumentProduitScanOfficiel::query()
            ->where('document_produit_direction_id', $document->id)
            ->count();
        $versionMaximale = DocumentProduitScanOfficiel::query()
            ->where('document_produit_direction_id', $document->id)
            ->max('version');
        $nombreScansActifs = DocumentProduitScanOfficiel::query()
            ->where('document_produit_direction_id', $document->id)
            ->where('est_actif', true)
            ->count();

        if ($nombreScansActifs !== 1 || (int) $versionMaximale !== (int) $scan->version || $nombreScans !== (int) $versionMaximale) {
            abort(404);
        }

        $numerisation = $scan->documentNumerise;
        if ($numerisation === null
            || $numerisation->numerisable_type !== $courrier->getMorphClass()
            || (int) $numerisation->numerisable_id !== $courrier->id
            || (int) $numerisation->version < 1
            || $numerisation->etape_circuit !== $étapeScanAttendue
            || $numerisation->source !== SourceDocumentNumerise::TELEVERSEMENT
            || (int) $numerisation->capture_par_id !== (int) $scan->depose_par_id
            || ! is_string($numerisation->chemin)
            || preg_match(
                '/\Adocuments-internes\/scans-officiels\/'.preg_quote((string) $document->id, '/').'\/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\.pdf\z/i',
                $numerisation->chemin,
            ) !== 1
            || ! is_string($numerisation->sha256)
            || preg_match('/\A[a-f0-9]{64}\z/', $numerisation->sha256) !== 1
            || ! is_numeric($numerisation->poids_octets)
            || (int) $numerisation->poids_octets < 1
            || ! is_numeric($numerisation->nombre_pages)
            || (int) $numerisation->nombre_pages < 1
            || $numerisation->qualite !== QualiteDocumentNumerise::BONNE
            || User::query()->whereKey($scan->depose_par_id)->doesntExist()
            || (($transmisReception || $accepteSec1 || $attenteDg) && User::query()->whereKey($document->transmis_reception_par_id)->doesntExist())
            || ($accepteSec1 && User::query()->whereKey($document->recu_par_id)->doesntExist())
            || ! Storage::disk('local')->exists($numerisation->chemin)) {
            abort(404);
        }

        $empreinteRequeteAttendue = hash('sha256', json_encode([
            'sha256' => $numerisation->sha256,
            'depose_par_id' => (int) $scan->depose_par_id,
            'attestation_confirmee' => true,
            'attestation_version' => self::ATTESTATION_VERSION,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (! hash_equals($scan->empreinte_requete, $empreinteRequeteAttendue)) {
            abort(404);
        }

        try {
            $contenu = Storage::disk('local')->get($numerisation->chemin);
        } catch (Throwable $exception) {
            Log::error('Le scan officiel actif ne peut pas être lu depuis le stockage privé.', [
                'document_id' => $document->id,
                'document_numerise_id' => $numerisation->id,
                'exception' => $exception,
            ]);
            abort(404);
        }

        if (! is_string($contenu)
            || strlen($contenu) !== (int) $numerisation->poids_octets
            || ! hash_equals($numerisation->sha256, hash('sha256', $contenu))
            || ! str_starts_with($contenu, '%PDF-')
            || ! str_contains($contenu, '%%EOF')) {
            Log::critical('Intégrité du scan officiel de document autonome compromise.', [
                'document_id' => $document->id,
                'document_numerise_id' => $numerisation->id,
            ]);
            abort(404);
        }

        return $this->reponseScanPrive($document->id, $contenu);
    }

    private function reponseScanPrive(int $documentId, string $contenu): StreamedResponse
    {
        return response()->stream(
            static function () use ($contenu): void {
                echo $contenu;
            },
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"scan-officiel-{$documentId}.pdf\"",
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function verifierPourTransmission(DocumentProduitDirection $document): void
    {
        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || $document->statut !== DocumentProduitStatut::SCAN_ENREGISTRE
            || (($document->transmis_reception_par_id === null) !== ($document->transmis_reception_at === null))
            || ($document->transmis_reception_par_id !== null && $document->correction_demandee_at === null)
            || $document->recu_par_id !== null
            || $document->recu_at !== null) {
            throw ValidationException::withMessages(['document' => 'Le document n’est pas dans un état cohérent pour la transmission.']);
        }

        $this->consulter($document);
    }

    public function verifierPourAcceptation(DocumentProduitDirection $document): void
    {
        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || $document->statut !== DocumentProduitStatut::TRANSMIS_RECEPTION
            || $document->transmis_reception_par_id === null
            || $document->transmis_reception_at === null
            || $document->recu_par_id !== null
            || $document->recu_at !== null) {
            throw ValidationException::withMessages(['document' => 'Le document n’est pas en attente de contrôle par la Réception.']);
        }

        $this->consulterTransmisReception($document);
    }

    public function verifierPourSec1(DocumentProduitDirection $document): void
    {
        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || $document->statut !== DocumentProduitStatut::ENTRE_CIRCUIT
            || $document->transmis_reception_par_id === null
            || $document->transmis_reception_at === null
            || $document->recu_par_id === null
            || $document->recu_at === null) {
            throw ValidationException::withMessages(['document' => 'Le document autonome n’a pas été accepté par la Réception.']);
        }

        $this->consulterAccepteSec1($document);
    }

    public function consulterTransmisReception(DocumentProduitDirection $document): StreamedResponse
    {
        return $this->consulterSelonEtat($document, 'reception');
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
            || (($document->transmis_reception_par_id === null) !== ($document->transmis_reception_at === null))
            || ($document->transmis_reception_par_id !== null
                && ($document->correction_demandee_at === null
                    || $document->version_contenu <= (int) $document->pdf_officiel_version_contenu))
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
