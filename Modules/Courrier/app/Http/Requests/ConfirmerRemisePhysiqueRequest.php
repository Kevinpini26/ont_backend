<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmerRemisePhysiqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('confirmerRemisePhysique', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'remis_a' => ['required', 'string', 'max:255'],
            'observation' => ['nullable', 'string', 'max:2000'],
            'decharge_remise' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }
}
