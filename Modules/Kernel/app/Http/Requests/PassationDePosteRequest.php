<?php

namespace Modules\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;

class PassationDePosteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'poste' => [
                'required',
                Rule::enum(Poste::class),
                Rule::notIn([Poste::PROTOCOLE->value, Poste::ASSISTANT_PROTOCOLE->value]),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Poste::tryFrom($value)?->estUnique() !== true) {
                        $fail('Ce poste n\'admet pas de titulaire unique : la passation ne s\'applique pas.');
                    }
                },
            ],
            'nouveau_titulaire_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::AGENT_CIRCUIT_COURRIER->value),
            ],
        ];
    }
}
