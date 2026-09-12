<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenvoyerAuTriRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('renvoyerAuTri', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'motif' => ['nullable', 'string'],
        ];
    }
}
