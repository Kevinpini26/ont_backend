<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;

class EmpruntOriginal extends Model
{
    public $timestamps = false;

    // Eloquent pluraliserait "emprunt_originals" (pluriel appliqué au seul
    // dernier mot) — même piège déjà rencontré pour
    // CourrierPieceJointe/EtablissementFormation.
    protected $table = 'emprunts_originaux';

    protected $fillable = [
        'courrier_id',
        'emprunte_par_id',
        'emprunte_le',
        'motif',
        'restitue_le',
        'restitue_par_id',
    ];

    protected function casts(): array
    {
        return [
            'emprunte_le' => 'datetime',
            'restitue_le' => 'datetime',
        ];
    }

    public function estEnCours(): bool
    {
        return $this->restitue_le === null;
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function empruntePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emprunte_par_id');
    }

    public function restituePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restitue_par_id');
    }
}
