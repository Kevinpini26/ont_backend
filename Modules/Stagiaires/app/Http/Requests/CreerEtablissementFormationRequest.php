<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Même garde que gererInformationsComplementaires() : la DFP est seule
 * responsable du dossier stagiaire, y compris de l'enrichissement du
 * référentiel des établissements.
 */
class CreerEtablissementFormationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererInformationsComplementaires', Stagiaire::class);
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'ville' => ['nullable', 'string', 'max:255'],
        ];
    }
}
