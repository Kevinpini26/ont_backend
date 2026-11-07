<?php

namespace Modules\Courrier\Models;

use Illuminate\Database\Eloquent\Model;

/** Lot 6 : rubrique du plan de classement (durée d'utilité administrative et sort final, réglés par l'administrateur). */
class PlanClassementRubrique extends Model
{
    public const SORTS_FINAUX = ['conserver', 'verser', 'detruire'];

    protected $table = 'plan_classement_rubriques';

    protected $fillable = ['code', 'libelle', 'duree_conservation_annees', 'sort_final', 'actif'];

    protected function casts(): array
    {
        return ['duree_conservation_annees' => 'integer', 'actif' => 'boolean'];
    }

    public function intitule(): string
    {
        return "{$this->code} — {$this->libelle}";
    }
}
