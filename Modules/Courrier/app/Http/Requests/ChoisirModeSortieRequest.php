<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\ModeSortie;

class ChoisirModeSortieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('choisirModeSortie', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'mode_sortie' => ['required', Rule::enum(ModeSortie::class)],
        ];
    }
}
