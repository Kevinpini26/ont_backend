<?php

namespace Modules\Stagiaires\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;

class StagiaireSuivi extends Model
{
    protected $fillable = [
        'stagiaire_id',
        'date_suivi',
        'observations',
        'difficultes_signalees',
        'redige_par_id',
    ];

    protected function casts(): array
    {
        return [
            'date_suivi' => 'date',
        ];
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class);
    }

    public function redigePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redige_par_id');
    }
}
