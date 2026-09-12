<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Models\User;

/**
 * Chaque ligne EST un bordereau de transmission, pas seulement un
 * historique de statut : elle porte le destinataire attendu (un poste, ou
 * une personne précise — le relecteur désigné, pour la transition vers
 * en_relecture) et, une fois la décharge donnée, qui a accusé réception et
 * quand — voir CourrierCircuitService::tracerTransition()/accuserReception().
 */
class CourrierTransition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'courrier_id',
        'statut',
        'tour',
        'changed_by_id',
        'agi_en_interim',
        'destinataire_poste',
        'destinataire_user_id',
        'bordereau_lot_id',
        'accuse_reception_par_id',
        'accuse_reception_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => CourrierStatut::class,
            'tour' => 'integer',
            'created_at' => 'datetime',
            'accuse_reception_at' => 'datetime',
            'agi_en_interim' => 'boolean',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    public function destinataireUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_user_id');
    }

    public function accuseReceptionPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accuse_reception_par_id');
    }

    public function bordereauLot(): BelongsTo
    {
        return $this->belongsTo(BordereauLot::class);
    }
}
