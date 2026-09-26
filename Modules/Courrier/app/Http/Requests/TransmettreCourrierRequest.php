<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransmettreCourrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transmettre', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'instruction' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
