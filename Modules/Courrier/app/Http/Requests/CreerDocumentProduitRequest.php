<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreerDocumentProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $t = $this->route('traitement');

        return $this->user()->role?->value === 'secretariat_direction' && $this->user()->direction_id === $t->direction_id;
    }

    public function rules(): array
    {
        return ['objet' => ['required', 'string', 'max:255'], 'contenu' => ['required', 'array']];
    }
}
