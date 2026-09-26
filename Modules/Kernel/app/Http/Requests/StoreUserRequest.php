<?php

namespace Modules\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Support\PasswordPolicy;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('role') === UserRole::RESPONSABLE_DIRECTION->value) {
            $this->merge(['role' => UserRole::DIRECTEUR_DIRECTION->value]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = UserRole::tryFrom((string) $this->input('role'));
        $fonctionDirectionnelle = $role?->estDirecteurDirection() || $role === UserRole::SECRETARIAT_DIRECTION;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', PasswordPolicy::regle()],
            'role' => ['required', Rule::enum(UserRole::class)],
            'poste' => [
                Rule::requiredIf(fn () => $this->input('role') === UserRole::AGENT_CIRCUIT_COURRIER->value),
                Rule::excludeIf(fn () => $this->input('role') !== UserRole::AGENT_CIRCUIT_COURRIER->value),
                Rule::enum(Poste::class),
                Rule::notIn([Poste::PROTOCOLE->value, Poste::ASSISTANT_PROTOCOLE->value]),
            ],
            'direction_id' => [
                Rule::requiredIf(fn () => in_array($this->input('role'), array_map(
                    fn (UserRole $r) => $r->value,
                    UserRole::rolesRequiringDirection(),
                ), true)),
                Rule::excludeIf(fn () => ! in_array($this->input('role'), array_map(
                    fn (UserRole $r) => $r->value,
                    UserRole::rolesRequiringDirection(),
                ), true)),
                'integer',
                Rule::exists('directions', 'id')->when(
                    $fonctionDirectionnelle,
                    fn ($rule) => $rule->where('est_operationnelle', true)->where('actif', true),
                ),
            ],
        ];
    }
}
