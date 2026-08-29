<?php

namespace Modules\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Kernel\Support\PasswordPolicy;

class ReinitialiserMotDePasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'mot_de_passe' => ['required', 'string', 'confirmed', PasswordPolicy::regle()],
        ];
    }
}
