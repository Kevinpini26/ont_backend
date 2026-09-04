<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Stagiaires\Models\TableauRepartition;

class RendreAvisTableauRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rendreAvis', TableauRepartition::class);
    }

    public function rules(): array
    {
        return [
            'approuve' => ['required', 'boolean'],
            'observations' => [Rule::requiredIf(fn () => $this->boolean('approuve') === false), 'nullable', 'string', 'max:2000'],
        ];
    }
}
