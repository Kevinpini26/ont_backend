<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Stagiaires\Models\TableauRepartition;

class CreerTableauRepartitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('creer', TableauRepartition::class);
    }

    public function rules(): array
    {
        return [
            'periode_debut' => ['required', 'date'],
            'periode_fin' => ['required', 'date', 'after_or_equal:periode_debut'],
        ];
    }
}
