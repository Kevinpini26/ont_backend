<?php

namespace Modules\Stagiaires\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stagiaires\Models\EtablissementFormation;

/**
 * @extends Factory<EtablissementFormation>
 */
class EtablissementFormationFactory extends Factory
{
    protected $model = EtablissementFormation::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->company().' - Institut Supérieur',
            'ville' => fake()->city(),
            'actif' => true,
        ];
    }
}
