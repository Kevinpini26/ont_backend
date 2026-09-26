<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreerMissionDocumentaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('creerMission', $this->route('courrier'));
    }

    public function rules(): array
    {
        return [
            'assistant_id' => ['required', 'integer', 'exists:users,id'],
            'instruction' => ['required', 'string', 'max:5000'],
        ];
    }
}
