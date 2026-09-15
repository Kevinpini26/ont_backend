<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lot assistants : observation obligatoire (contrairement à
 * ValiderRelectureRequest::relecture_commentaire, nullable) — un renvoi sans
 * justification laisserait l'assistant rédacteur deviner ce qui ne va pas.
 */
class RenvoyerPourCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('renvoyerPourCorrection', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'observation' => ['required', 'string', 'max:2000'],
        ];
    }
}
