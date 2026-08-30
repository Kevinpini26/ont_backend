<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreerSuiviStagiaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererSuivi', $this->route('stagiaire'));
    }

    public function rules(): array
    {
        return [
            'date_suivi' => ['required', 'date'],
            'observations' => ['required', 'string', 'max:5000'],
            'difficultes_signalees' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
