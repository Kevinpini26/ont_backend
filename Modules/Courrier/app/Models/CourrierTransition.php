<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Models\User;

/**
 * Chaque ligne conserve une transition de statut. Quand le dossier est remis
 * à un autre poste ou à un relecteur précis, elle porte aussi son destinataire
 * et les données de décharge — voir CourrierCircuitService::tracerTransition().
 */
class CourrierTransition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'courrier_id',
        'statut',
        'ancien_statut',
        'nouveau_statut',
        'tour',
        'changed_by_id',
        'expediteur_poste',
        'instruction',
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
            'ancien_statut' => CourrierStatut::class,
            'nouveau_statut' => CourrierStatut::class,
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
