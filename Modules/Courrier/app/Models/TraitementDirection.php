<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Courrier\Enums\DecisionDirection;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class TraitementDirection extends Model
{
    protected $table = 'traitements_direction';

    protected $fillable = [
        'courrier_id', 'dossier_id', 'dispatch_courrier_id', 'direction_id', 'statut',
        'recu_par_secretariat_id', 'recu_secretariat_at', 'transmis_par_secretariat_id',
        'directeur_id', 'note_transmission', 'transmis_directeur_at', 'pris_en_charge_par_id',
        'pris_en_charge_at', 'decision_directeur', 'commentaire_directeur', 'decision_par_id', 'decision_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => TraitementDirectionStatut::class,
            'decision_directeur' => DecisionDirection::class,
            'recu_secretariat_at' => 'datetime', 'transmis_directeur_at' => 'datetime',
            'pris_en_charge_at' => 'datetime', 'decision_at' => 'datetime',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(DispatchCourrier::class, 'dispatch_courrier_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function recuParSecretariat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recu_par_secretariat_id');
    }

    public function transmisParSecretariat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transmis_par_secretariat_id');
    }

    public function directeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'directeur_id');
    }

    public function prisEnChargePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pris_en_charge_par_id');
    }

    public function decisionPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_par_id');
    }

    public function documentProduit(): HasOne
    {
        return $this->hasOne(DocumentProduitDirection::class, 'traitement_direction_id');
    }
}
