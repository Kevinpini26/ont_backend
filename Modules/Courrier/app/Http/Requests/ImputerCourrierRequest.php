<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Courrier\Enums\MentionImputation;

class ImputerCourrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('imputer', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'imputations' => ['required', 'array', 'min:1'],
            'imputations.*.direction_id' => ['required', 'integer', 'exists:directions,id', 'distinct'],
            'imputations.*.mention' => ['required', Rule::enum(MentionImputation::class)],
            'imputations.*.est_principale' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $principales = collect($this->input('imputations', []))->where('est_principale', true);

            if ($principales->count() !== 1) {
                $validator->errors()->add('imputations', 'Une et une seule direction imputée à titre principal est requise.');
            }
        });
    }
}
