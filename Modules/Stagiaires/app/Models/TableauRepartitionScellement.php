<?php

namespace Modules\Stagiaires\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kernel\Models\User;

/**
 * Scellement du feu vert (Lot D, point 3) : posé une seule fois, à
 * l'approbation du tableau (voir TableauRepartitionCircuitService::
 * rendreAvis()) — volontairement AUCUNE méthode de mise à jour n'est
 * exposée ici, pour que le caractère non modifiable de la preuve ne
 * dépende pas de la seule discipline des appelants.
 */
class TableauRepartitionScellement extends Model
{
    public $timestamps = false;

    protected $table = 'tableau_repartition_scellements';

    protected $fillable = [
        'tableau_repartition_id',
        'pdf_sha256',
        'auteur_id',
        'mention_interim',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'mention_interim' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function tableau(): BelongsTo
    {
        return $this->belongsTo(TableauRepartition::class, 'tableau_repartition_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
