<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetournerMissionDocumentaireRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('projet_reponse_contenu'))) {
            $this->merge(['projet_reponse_contenu' => json_decode($this->input('projet_reponse_contenu'), true)]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('retourner', $this->route('mission'));
    }

    public function rules(): array
    {
        return [
            'compte_rendu' => ['required', 'string', 'max:10000'],
            'projet_reponse_contenu' => ['nullable', 'array'],
        ];
    }
}
