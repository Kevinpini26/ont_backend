<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;

/**
 * En-tête d'une transmission par lot (Lot C, points 1-2) : la Réception (ou
 * tout autre poste) transmet plusieurs dossiers d'un même geste, avec une
 * décharge unique — mais chaque dossier garde sa propre trace individuelle
 * (voir `transitions()`, chacune une vraie CourrierTransition avec son
 * propre `courrier_id`), consultable et opposable séparément.
 */
class BordereauLot extends Model
{
    protected $table = 'bordereaux_lot';

    protected $fillable = [
        'numero',
        'emetteur_id',
        'poste_destinataire',
        'accuse_reception_at',
        'accuse_reception_par_id',
    ];

    protected function casts(): array
    {
        return [
            'poste_destinataire' => Poste::class,
            'accuse_reception_at' => 'datetime',
        ];
    }

    public function emetteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emetteur_id');
    }

    public function accuseReceptionPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accuse_reception_par_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(CourrierTransition::class, 'bordereau_lot_id');
    }

    public function courriers(): HasManyThrough
    {
        return $this->hasManyThrough(
            Courrier::class,
            CourrierTransition::class,
            'bordereau_lot_id',
            'id',
            'id',
            'courrier_id',
        );
    }

    public function acquitte(): bool
    {
        return $this->accuse_reception_at !== null;
    }
}
