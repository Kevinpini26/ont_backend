<?php

namespace Modules\Kernel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Enums\QualiteDocumentNumerise;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Exceptions\DocumentNumeriseRejeteException;
use Modules\Kernel\Models\DocumentNumerise;
use Modules\Kernel\Models\User;

/**
 * Point d'entrée unique pour enregistrer une nouvelle version d'un document
 * numérisé, quelle que soit la source (capture mobile aujourd'hui, import
 * par lot USB et surveillance de dossier demain — voir SourceNumerisation,
 * docs/numerisation-courrier.md) : calcule les métadonnées côté serveur
 * (poids, empreinte, nombre de pages, qualité) plutôt que de faire
 * confiance aux seules déclarations du client.
 */
class GestionnaireDocumentNumerise
{
    /**
     * @param  Model  $numerisable  Courrier ou Stagiaire
     *
     * @throws DocumentNumeriseRejeteException si le poids par page est trop faible
     */
    public function enregistrerVersion(
        Model $numerisable,
        string $chemin,
        SourceDocumentNumerise $source,
        ?User $capturePar = null,
        ?int $nombrePagesAnnonce = null,
        string $disque = 'local',
    ): DocumentNumerise {
        $contenu = Storage::disk($disque)->get($chemin);
        $poidsOctets = Storage::disk($disque)->size($chemin);
        $nombrePagesDetectees = CompteurPagesPdf::compter($contenu);

        if ($nombrePagesDetectees !== null && ($poidsOctets / $nombrePagesDetectees) < config('kernel.numerisation.seuil_octets_par_page')) {
            Storage::disk($disque)->delete($chemin);

            throw DocumentNumeriseRejeteException::qualiteInsuffisante();
        }

        $qualite = ($nombrePagesAnnonce !== null && $nombrePagesDetectees !== null && $nombrePagesAnnonce !== $nombrePagesDetectees)
            ? QualiteDocumentNumerise::FAIBLE
            : QualiteDocumentNumerise::BONNE;

        $versionSuivante = ((int) $numerisable->numerisations()->max('version')) + 1;

        // Courrier::statut et Stagiaire::statut sont chacun castés vers
        // leur propre enum : la valeur brute suffit ici comme simple
        // libellé d'étape, sans avoir besoin de connaître le type concret
        // de $numerisable.
        /** @var DocumentNumerise */
        return $numerisable->numerisations()->create([
            'version' => $versionSuivante,
            'etape_circuit' => $numerisable->statut?->value,
            'chemin' => $chemin,
            'nombre_pages' => $nombrePagesDetectees,
            'poids_octets' => $poidsOctets,
            'source' => $source,
            'sha256' => hash('sha256', $contenu),
            'qualite' => $qualite,
            'capture_par_id' => $capturePar?->id,
        ]);
    }
}
