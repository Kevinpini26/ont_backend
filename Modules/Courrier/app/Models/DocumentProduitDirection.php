<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class DocumentProduitDirection extends Model
{
    protected $table = 'documents_produits_direction';

    protected $fillable = ['origine', 'traitement_direction_id', 'courrier_id', 'document_source_id', 'direction_id', 'directeur_id', 'version_contenu', 'modifie_par_id', 'modifie_at', 'statut', 'cree_par_id', 'cree_at', 'soumis_par_id', 'soumis_at', 'correction_demandee_par_id', 'motif_correction', 'correction_demandee_at', 'valide_par_id', 'valide_at', 'transmis_reception_par_id', 'transmis_reception_at', 'recu_par_id', 'recu_at'];

    protected function casts(): array
    {
        return ['origine' => DocumentProduitOrigine::class, 'statut' => DocumentProduitStatut::class, 'version_contenu' => 'integer', 'cree_at' => 'datetime', 'modifie_at' => 'datetime', 'soumis_at' => 'datetime', 'correction_demandee_at' => 'datetime', 'valide_at' => 'datetime', 'transmis_reception_at' => 'datetime', 'recu_at' => 'datetime'];
    }

    public function traitement(): BelongsTo
    {
        return $this->belongsTo(TraitementDirection::class, 'traitement_direction_id');
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function documentSource(): BelongsTo
    {
        return $this->belongsTo(Courrier::class, 'document_source_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function modifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par_id');
    }

    public function soumisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soumis_par_id');
    }

    public function correctionDemandeePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'correction_demandee_par_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par_id');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'sujet', 'auditable_type', 'auditable_id');
    }

    public function directeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'directeur_id');
    }
}
