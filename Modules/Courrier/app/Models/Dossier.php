<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Kernel\Models\User;

class Dossier extends Model
{
    protected $fillable = ['rubrique_classement_id', 'sort_final_bordereau_id', 'sort_final_execute_at', 'libelle', 'created_by', 'importe_historique', 'statut_archivage', 'archivage_decide_par_id', 'archivage_decide_at', 'archive_par_id', 'archive_at'];

    protected function casts(): array
    {
        return ['sort_final_execute_at' => 'datetime', 'importe_historique' => 'boolean', 'archivage_decide_at' => 'datetime', 'archive_at' => 'datetime'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Courrier::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<PlanClassementRubrique, $this> */
    public function rubriqueClassement(): BelongsTo
    {
        return $this->belongsTo(PlanClassementRubrique::class, 'rubrique_classement_id');
    }

    /** Lot 6 : date de fin de conservation (archivage + durée de la rubrique), ou null si inconnue. */
    public function finConservationLe(): ?Carbon
    {
        $rubrique = $this->rubriqueClassement;
        if ($rubrique === null || $rubrique->duree_conservation_annees === null || $this->archive_at === null) {
            return null;
        }

        return $this->archive_at->copy()->addYears($rubrique->duree_conservation_annees)->startOfDay();
    }
}
