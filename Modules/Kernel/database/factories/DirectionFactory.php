<?php

namespace Modules\Kernel\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Kernel\Models\Direction;

/**
 * @extends Factory<Direction>
 */
class DirectionFactory extends Factory
{
    protected $model = Direction::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'nom' => fake()->company(),
            'actif' => true,
            'est_operationnelle' => true,
        ];
    }
}
