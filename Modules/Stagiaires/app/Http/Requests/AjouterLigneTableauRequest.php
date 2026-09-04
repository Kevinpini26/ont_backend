<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AjouterLigneTableauRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('modifier', $this->route('tableau'));
    }

    public function rules(): array
    {
        return [
            'stagiaire_id' => ['required', 'integer', 'exists:stagiaires,id'],
            'direction_accueil_proposee_id' => ['required', 'integer', 'exists:directions,id'],
            'date_debut_proposee' => ['required', 'date'],
            'date_fin_proposee' => ['required', 'date', 'after_or_equal:date_debut_proposee'],
            'encadrant_pressenti' => ['required', 'string', 'max:255'],
        ];
    }
}
