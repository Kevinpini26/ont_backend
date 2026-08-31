<?php

namespace Modules\Stagiaires\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\DocumentType;

class StagiaireDocument extends Model
{
    protected $fillable = [
        'stagiaire_id',
        'type',
        'nom_original',
        'chemin',
        'sha256',
        'uploaded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
        ];
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class);
    }

    public function uploadePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
