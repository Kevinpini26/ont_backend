<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SoumettreDocumentProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('soumettre', $this->route('document'));
    }

    public function rules(): array
    {
        $document = $this->route('document');

        return [
            'objet' => ['sometimes', 'required', 'string', 'max:255'],
            'contenu' => ['sometimes', 'required', 'array'],
            'version_contenu' => [
                $document?->origine?->value === 'autonome' ? 'required' : 'sometimes',
                'integer',
                'min:1',
            ],
        ];
    }
}
