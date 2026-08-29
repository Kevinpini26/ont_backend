<?php

namespace Modules\Kernel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Kernel\Models\Direction;

class StoreDirectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Direction::class);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'unique:directions,code'],
            'nom' => ['required', 'string', 'max:255'],
            'actif' => ['sometimes', 'boolean'],
            'capacite_max' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
