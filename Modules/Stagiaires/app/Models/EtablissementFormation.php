<?php

namespace Modules\Stagiaires\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Stagiaires\Database\Factories\EtablissementFormationFactory;

class EtablissementFormation extends Model
{
    /** @use HasFactory<EtablissementFormationFactory> */
    use HasFactory;

    // Le pluriel Eloquent par défaut ("etablissement_formations") pluralise
    // le mauvais mot ; "etablissements_formation" est grammaticalement
    // correct (même situation que CourrierPieceJointe).
    protected $table = 'etablissements_formation';

    protected $fillable = [
        'nom',
        'ville',
        'actif',
    ];

    protected static function newFactory(): EtablissementFormationFactory
    {
        return EtablissementFormationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function stagiaires(): HasMany
    {
        return $this->hasMany(Stagiaire::class, 'etablissement_id');
    }
}
