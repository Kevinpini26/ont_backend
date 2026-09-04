<?php

namespace Modules\Stagiaires\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\TableauRepartitionStatut;

class TableauRepartition extends Model
{
    protected $table = 'tableaux_repartition';

    protected $fillable = [
        'courrier_id',
        'direction_id',
        'redacteur_id',
        'periode_debut',
        'periode_fin',
        'statut',
        'approuve_par_id',
        'approuve_at',
        'pdf_chemin',
    ];

    protected function casts(): array
    {
        return [
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'statut' => TableauRepartitionStatut::class,
            'approuve_at' => 'datetime',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function redacteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redacteur_id');
    }

    public function approuvePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(TableauRepartitionLigne::class);
    }

    public function modifiable(): bool
    {
        return $this->statut === TableauRepartitionStatut::BROUILLON;
    }
}
