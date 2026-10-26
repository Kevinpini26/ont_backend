<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/** Copie « pour information » d'un courrier adressée à une direction : lecture seule, aucun traitement attendu. */
class CourrierCopie extends Model
{
    protected $table = 'courrier_copies';

    protected $fillable = ['courrier_id', 'direction_id', 'ajoute_par_id', 'note'];

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function ajoutePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ajoute_par_id');
    }
}
