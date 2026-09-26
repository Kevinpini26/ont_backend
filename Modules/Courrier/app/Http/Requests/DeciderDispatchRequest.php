<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\DispatchTypeDestination;

class DeciderDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deciderDispatch', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'destinations' => ['required', 'array', 'min:1', 'max:20'],
            'destinations.*.type' => ['required', Rule::enum(DispatchTypeDestination::class)],
            'destinations.*.direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'destinations.*.destinataire_externe_nom' => ['nullable', 'string', 'max:255'],
            'destinations.*.destinataire_externe_email' => ['nullable', 'email', 'max:255'],
            'destinations.*.instruction' => ['required', 'string', 'max:5000'],
        ];
    }
}
