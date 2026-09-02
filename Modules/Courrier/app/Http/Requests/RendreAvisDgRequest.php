<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\AvisDg;

class RendreAvisDgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transmettre', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'avis_dg' => ['required', Rule::enum(AvisDg::class)],
            // Obligatoire pour un avis réservé (le dossier boucle : sans
            // observation, personne ne saurait ce qui manque au retour) —
            // facultatif sinon.
            'avis_dg_commentaire' => ['nullable', 'string', Rule::requiredIf($this->input('avis_dg') === AvisDg::RESERVE->value)],
        ];
    }

    public function messages(): array
    {
        return [
            'avis_dg_commentaire.required' => "Un avis réservé doit préciser ce qui est attendu pour que le dossier puisse revenir complet.",
        ];
    }
}
