<?php

namespace Modules\Kernel\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Kernel\Models\Site;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $ville = fake()->city();

        return [
            'nom' => 'Représentation provinciale de '.$ville,
            'ville' => $ville,
            'province' => fake()->randomElement([
                'Kongo-Central', 'Nord-Kivu', 'Sud-Kivu', 'Haut-Katanga', 'Kasaï',
            ]),
            'siege' => false,
            'actif' => true,
        ];
    }
}
