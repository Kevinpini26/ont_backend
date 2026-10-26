<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courrier\Enums\ConfiageDgaStatut;
use Modules\Kernel\Models\User;

/** Dossier confié par le DG au DGA « pour instruction » : le DGA l'instruit puis le retourne au DG avec une recommandation. */
class ConfiageDga extends Model
{
    protected $table = 'confiages_dga';

    protected $fillable = [
        'courrier_id', 'confie_par_id', 'dga_id', 'consigne', 'statut', 'confie_at',
        'retourne_par_id', 'retourne_at', 'recommandation', 'repris_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => ConfiageDgaStatut::class,
            'confie_at' => 'datetime',
            'retourne_at' => 'datetime',
            'repris_at' => 'datetime',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function confiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confie_par_id');
    }

    public function dga(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dga_id');
    }

    public function retournePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retourne_par_id');
    }
}
