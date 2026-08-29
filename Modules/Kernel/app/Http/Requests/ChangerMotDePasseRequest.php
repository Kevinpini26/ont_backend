<?php

namespace Modules\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Kernel\Support\PasswordPolicy;

class ChangerMotDePasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ancien_mot_de_passe' => ['required', 'string'],
            'mot_de_passe' => ['required', 'string', 'confirmed', PasswordPolicy::regle()],
        ];
    }
}
