<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class DispatchCourrier extends Model
{
    protected $table = 'dispatchs_courrier';

    protected $fillable = [
        'courrier_id', 'dossier_id', 'cycle', 'type_destination', 'direction_id',
        'destinataire_externe_nom', 'destinataire_externe_email', 'instruction',
        'decisionnaire_id', 'decisionnaire_poste', 'autorite_poste', 'decide_at',
        'statut', 'execute_par_id', 'execute_at', 'reference_transmission',
        'preuve_piece_jointe_id', 'accuse_reception_par_id', 'accuse_reception_at',
    ];

    protected function casts(): array
    {
        return [
            'type_destination' => DispatchTypeDestination::class,
            'cycle' => 'integer',
            'statut' => DispatchStatut::class,
            'decisionnaire_poste' => Poste::class,
            'autorite_poste' => Poste::class,
            'decide_at' => 'datetime',
            'execute_at' => 'datetime',
            'accuse_reception_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Courrier, $this> */
    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function decisionnaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decisionnaire_id');
    }

    public function executePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'execute_par_id');
    }

    public function preuve(): BelongsTo
    {
        return $this->belongsTo(CourrierPieceJointe::class, 'preuve_piece_jointe_id');
    }

    public function accuseReceptionPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accuse_reception_par_id');
    }

    public function traitementDirection(): HasOne
    {
        return $this->hasOne(TraitementDirection::class, 'dispatch_courrier_id');
    }
}
