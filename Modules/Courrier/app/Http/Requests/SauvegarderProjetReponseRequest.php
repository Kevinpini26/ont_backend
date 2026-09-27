<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SauvegarderProjetReponseRequest extends FormRequest
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
        return [
            'objet' => ['sometimes', 'required', 'string', 'max:255'],
            'projet_reponse_contenu' => ['required', 'array'],
            'destinataire_externe_nom' => ['sometimes', 'required', 'string', 'max:255'],
            'destinataire_externe_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
