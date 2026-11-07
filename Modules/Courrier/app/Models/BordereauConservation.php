<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Kernel\Models\User;

/** Lot 6 : bordereau d'élimination ou de versement, proposé par SEC2 et validé par une autre personne. */
class BordereauConservation extends Model
{
    protected $table = 'bordereaux_conservation';

    protected $fillable = ['numero', 'type', 'statut', 'motif', 'propose_par_id', 'propose_at', 'valide_par_id', 'valide_at'];

    protected function casts(): array
    {
        return ['propose_at' => 'datetime', 'valide_at' => 'datetime'];
    }

    /** @return BelongsToMany<Dossier, $this> */
    public function dossiers(): BelongsToMany
    {
        return $this->belongsToMany(Dossier::class, 'bordereau_conservation_dossier')->withPivot(['rubrique', 'fin_conservation_le']);
    }

    /** @return BelongsTo<User, $this> */
    public function proposePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propose_par_id');
    }

    /** @return BelongsTo<User, $this> */
    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par_id');
    }

    public function libelleType(): string
    {
        return $this->type === 'versement' ? 'Versement aux Archives nationales' : 'Élimination';
    }
}
