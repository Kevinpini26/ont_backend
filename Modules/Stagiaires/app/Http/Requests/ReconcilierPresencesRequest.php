<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Stagiaires\Models\Stagiaire;

class ReconcilierPresencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererPresence', Stagiaire::class);
    }

    public function rules(): array
    {
        return [
            'entrees' => ['required', 'array', 'min:1'],
            'entrees.*.date' => ['required', 'date'],
            'entrees.*.heure_arrivee' => ['nullable', 'date_format:H:i'],
            'entrees.*.heure_depart' => ['nullable', 'date_format:H:i'],
            'forcer' => ['nullable', 'boolean'],
        ];
    }
}
