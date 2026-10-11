<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SauvegarderBrouillonDocumentProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('modifierBrouillon', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'version_contenu' => ['required', 'integer', 'min:1'],
            'objet' => ['required', 'string', 'max:255'],
            'contenu' => ['required', 'array'],
        ];
    }
}
