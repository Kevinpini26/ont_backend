<?php

namespace Modules\Kernel\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::RESPONSABLE_DIRECTION,
            'poste' => null,
            'direction_id' => Direction::factory(),
        ];
    }

    public function administrateur(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::ADMINISTRATEUR,
            'poste' => null,
            'direction_id' => null,
        ]);
    }

    public function agentDfp(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::AGENT_DFP,
            'poste' => null,
            'direction_id' => null,
        ]);
    }

    public function responsableDirection(?Direction $direction = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::RESPONSABLE_DIRECTION,
            'poste' => null,
            'direction_id' => $direction?->id ?? Direction::factory(),
        ]);
    }

    public function secretariatDirection(?Direction $direction = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::SECRETARIAT_DIRECTION,
            'poste' => null,
            'direction_id' => $direction?->id ?? Direction::factory(),
        ]);
    }

    public function agentCircuitCourrier(Poste $poste, ?Direction $direction = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::AGENT_CIRCUIT_COURRIER,
            'poste' => $poste,
            'direction_id' => $direction?->id ?? Direction::factory(),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
