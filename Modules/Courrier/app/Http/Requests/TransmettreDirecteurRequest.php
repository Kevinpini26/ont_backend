<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransmettreDirecteurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transmettreDirecteur', $this->route('traitement'));
    }

    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:5000']];
    }
}
