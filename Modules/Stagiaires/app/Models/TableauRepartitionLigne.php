<?php

namespace Modules\Stagiaires\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\Direction;

class TableauRepartitionLigne extends Model
{
    protected $table = 'tableau_repartition_lignes';

    protected $fillable = [
        'tableau_repartition_id',
        'stagiaire_id',
        'direction_accueil_proposee_id',
        'date_debut_proposee',
        'date_fin_proposee',
        'encadrant_pressenti',
    ];

    protected function casts(): array
    {
        return [
            'date_debut_proposee' => 'date',
            'date_fin_proposee' => 'date',
        ];
    }

    public function tableau(): BelongsTo
    {
        return $this->belongsTo(TableauRepartition::class, 'tableau_repartition_id');
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class);
    }

    public function directionAccueilProposee(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_accueil_proposee_id');
    }
}
