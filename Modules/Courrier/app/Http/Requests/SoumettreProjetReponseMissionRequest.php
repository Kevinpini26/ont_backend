<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SoumettreProjetReponseMissionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('projet_reponse_contenu'))) {
            $this->merge(['projet_reponse_contenu' => json_decode($this->input('projet_reponse_contenu'), true)]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('redigerProjet', $this->route('mission'));
    }

    public function rules(): array
    {
        return ['projet_reponse_contenu' => ['required', 'array']];
    }
}
