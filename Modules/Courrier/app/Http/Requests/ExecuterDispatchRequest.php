<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuterDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executer', $this->route('dispatch'));
    }

    public function rules(): array
    {
        return [
            'reference_transmission' => ['nullable', 'string', 'max:255'],
            'preuve' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ];
    }
}
