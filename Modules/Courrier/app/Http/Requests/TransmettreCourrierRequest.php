<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransmettreCourrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route()?->getActionMethod() === 'transmettreSec1') {
            return $this->user()->can('transmettreSec1', $this->route('courrier'));
        }

        return $this->user()->can('transmettre', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'instruction' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
