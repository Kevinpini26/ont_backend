<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Kernel\Enums\QualiteDocumentNumerise;
use Modules\Kernel\Enums\SourceDocumentNumerise;

/**
 * Une version numérisée d'un document (courrier ou dossier stagiaire) —
 * jamais un remplacement de la version précédente : voir la migration
 * create_documents_numerises_table pour pourquoi (le papier continue de
 * vivre après son arrivée : annotation DG, cachet Protocole...).
 */
class DocumentNumerise extends Model
{
    // Eloquent pluraliserait "document_numerises" (pluriel appliqué au nom
    // entier, pas au seul "document") — même piège déjà rencontré pour
    // CourrierPieceJointe/EtablissementFormation.
    protected $table = 'documents_numerises';

    protected $fillable = [
        'numerisable_type',
        'numerisable_id',
        'version',
        'etape_circuit',
        'chemin',
        'nombre_pages',
        'poids_octets',
        'source',
        'sha256',
        'qualite',
        'capture_par_id',
        'contenu_texte',
    ];

    protected function casts(): array
    {
        return [
            'source' => SourceDocumentNumerise::class,
            'qualite' => QualiteDocumentNumerise::class,
        ];
    }

    public function numerisable(): MorphTo
    {
        return $this->morphTo();
    }

    public function capturePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capture_par_id');
    }
}
