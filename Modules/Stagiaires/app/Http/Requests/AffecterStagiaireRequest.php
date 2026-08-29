<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Stagiaires\Models\Stagiaire;

class AffecterStagiaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('affecter', Stagiaire::class);
    }

    public function rules(): array
    {
        return [
            'direction_id' => ['required', 'integer', 'exists:directions,id'],
            'forcer' => ['sometimes', 'boolean'],
            'justification' => ['required_if:forcer,true', 'nullable', 'string', 'max:1000'],
        ];
    }
}
