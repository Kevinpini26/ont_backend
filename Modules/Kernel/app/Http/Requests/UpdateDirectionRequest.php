<?php

namespace Modules\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDirectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('direction'));
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('directions', 'code')->ignore($this->route('direction'))],
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'actif' => ['sometimes', 'boolean'],
            'est_operationnelle' => ['sometimes', 'boolean'],
            'capacite_max' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'site_id' => ['sometimes', 'nullable', 'integer', 'exists:sites,id'],
        ];
    }
}
