<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Stagiaires\Models\Stagiaire;

class DefinirInformationsComplementairesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererInformationsComplementaires', Stagiaire::class);
    }

    public function rules(): array
    {
        return [
            'lieu_naissance' => ['nullable', 'string', 'max:255'],
            'filiere_formation' => ['nullable', 'string', 'max:255'],
            'niveau_formation' => ['nullable', 'string', 'max:255'],
            'session_promotion' => ['nullable', 'string', 'max:255'],
            'etablissement_id' => ['nullable', 'integer', 'exists:etablissements_formation,id'],
            'maitre_stage' => ['nullable', 'string', 'max:255'],
            'maitre_stage_id' => ['nullable', 'integer', 'exists:users,id'],
            'conseiller_stage' => ['nullable', 'string', 'max:255'],
        ];
    }
}
