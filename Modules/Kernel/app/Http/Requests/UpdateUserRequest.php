<?php

namespace Modules\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Support\PasswordPolicy;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['sometimes', 'required', 'string', PasswordPolicy::regle()],
            'role' => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'poste' => [
                Rule::requiredIf(fn () => $this->input('role', $user?->role?->value) === UserRole::AGENT_CIRCUIT_COURRIER->value),
                Rule::excludeIf(fn () => $this->input('role', $user?->role?->value) !== UserRole::AGENT_CIRCUIT_COURRIER->value),
                Rule::enum(Poste::class),
            ],
            'direction_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('directions', 'id'),
            ],
        ];
    }
}
