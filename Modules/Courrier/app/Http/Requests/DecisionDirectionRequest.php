<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;
use Modules\Courrier\Enums\DecisionDirection;

class DecisionDirectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decider', $this->route('traitement'));
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(DecisionDirection::class)],
            'commentaire' => [new RequiredIf($this->input('decision') === DecisionDirection::AUTRE->value), 'nullable', 'string', 'max:5000'],
        ];
    }
}
