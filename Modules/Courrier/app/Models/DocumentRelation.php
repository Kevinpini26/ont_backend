<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Kernel\Models\User;

class DocumentRelation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['document_source_id', 'document_cible_id', 'type_relation', 'created_by', 'importe_historique'];

    protected function casts(): array
    {
        return ['type_relation' => DocumentRelationType::class, 'importe_historique' => 'boolean'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Courrier::class, 'document_source_id');
    }

    public function cible(): BelongsTo
    {
        return $this->belongsTo(Courrier::class, 'document_cible_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
