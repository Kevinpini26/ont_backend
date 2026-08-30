<?php

namespace Modules\Kernel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Enums\Poste;

class DelegationPoste extends Model
{
    protected $table = 'delegations_poste';

    protected $fillable = [
        'poste',
        'delegataire_id',
        'debut',
        'fin',
        'motif',
        'cree_par_id',
    ];

    protected function casts(): array
    {
        return [
            'poste' => Poste::class,
            'debut' => 'date',
            'fin' => 'date',
        ];
    }

    public function scopeActivesLe(Builder $query, $date): Builder
    {
        return $query->whereDate('debut', '<=', $date)->whereDate('fin', '>=', $date);
    }

    public function delegataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegataire_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
