<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DemanderCorrectionDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('statuer', $this->route('document'));
    }

    public function rules(): array
    {
        return ['motif' => ['required', 'string', 'max:5000']];
    }
}
