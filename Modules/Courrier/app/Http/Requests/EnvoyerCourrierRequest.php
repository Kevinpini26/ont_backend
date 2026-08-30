<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\ModeExpedition;

class EnvoyerCourrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('envoyer', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'destinataire_externe_nom' => ['required', 'string', 'max:255'],
            'destinataire_externe_email' => ['nullable', 'email', 'max:255'],
            'mode_expedition' => ['required', Rule::enum(ModeExpedition::class)],
        ];
    }
}
