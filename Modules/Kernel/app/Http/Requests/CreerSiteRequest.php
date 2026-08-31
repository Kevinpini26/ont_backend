<?php

namespace Modules\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Kernel\Enums\UserRole;

class CreerSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === UserRole::ADMINISTRATEUR;
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'ville' => ['required', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'siege' => ['sometimes', 'boolean'],
        ];
    }
}
