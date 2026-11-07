<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Courrier\Models\DocumentProduitDirection;

class CreerDocumentProduitAutonomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createAutonome', DocumentProduitDirection::class);
    }

    public function rules(): array
    {
        return [
            'objet' => ['required', 'string', 'max:255'],
            'contenu' => ['required', 'array'],
            'direction_destination_souhaitee_id' => ['nullable', 'integer', 'exists:directions,id'],
            'nature' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('courrier.circuit_direct.natures', [])))],
        ];
    }
}
