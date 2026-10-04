<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RendreDisponibleRetraitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rendreDisponiblePourRetrait', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'observation' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
