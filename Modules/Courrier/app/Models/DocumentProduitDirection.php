<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class DocumentProduitDirection extends Model
{
    protected $table = 'documents_produits_direction';

    protected $fillable = ['traitement_direction_id', 'courrier_id', 'document_source_id', 'direction_id', 'statut', 'cree_par_id', 'cree_at', 'soumis_par_id', 'soumis_at', 'correction_demandee_par_id', 'motif_correction', 'correction_demandee_at', 'valide_par_id', 'valide_at', 'transmis_reception_par_id', 'transmis_reception_at', 'recu_par_id', 'recu_at'];

    protected function casts(): array
    {
        return ['statut' => DocumentProduitStatut::class, 'cree_at' => 'datetime', 'soumis_at' => 'datetime', 'correction_demandee_at' => 'datetime', 'valide_at' => 'datetime', 'transmis_reception_at' => 'datetime', 'recu_at' => 'datetime'];
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

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par_id');
    }
}
