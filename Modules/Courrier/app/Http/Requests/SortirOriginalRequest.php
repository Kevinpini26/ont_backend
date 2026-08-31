<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SortirOriginalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'motif' => ['required', 'string', 'max:500'],
        ];
    }
}
