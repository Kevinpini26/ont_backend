<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\ModeRemise;

class EnregistrerRemiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('enregistrerRemise', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'remis_a' => ['required', 'string', 'max:255'],
            'mode_remise' => ['required', Rule::enum(ModeRemise::class)],
            'decharge_remise' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }
}
