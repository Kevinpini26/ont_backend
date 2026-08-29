<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Stagiaires\Models\Stagiaire;

class ReaffecterStagiaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reaffecter', Stagiaire::class);
    }

    public function rules(): array
    {
        return [
            'direction_id' => ['required', 'integer', 'exists:directions,id'],
            'forcer' => ['sometimes', 'boolean'],
            'justification' => ['required', 'string', 'max:1000'],
        ];
    }
}
