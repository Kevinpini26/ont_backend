<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DemanderCorrectionReceptionDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('demanderCorrectionAutonomeReception', $this->route('document'));
    }

    protected function prepareForValidation(): void
    {
        $motif = $this->input('motif');
        if (is_string($motif)) {
            $this->merge(['motif' => trim($motif)]);
        }
    }

    public function rules(): array
    {
        return ['motif' => ['required', 'string', 'min:5', 'max:5000']];
    }
}
