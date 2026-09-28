<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetournerMissionDocumentaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('retourner', $this->route('mission'));
    }

    public function rules(): array
    {
        return [
            'compte_rendu' => ['required', 'string', 'max:10000'],
            'projet_reponse_contenu' => ['prohibited'],
        ];
    }
}
