<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\DegreUrgence;

class RequalifierUrgenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('requalifierUrgence', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'degre_urgence' => ['required', Rule::enum(DegreUrgence::class)],
        ];
    }
}
