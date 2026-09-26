<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnnulerMissionDocumentaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('annuler', $this->route('mission'));
    }

    public function rules(): array
    {
        return ['motif' => ['required', 'string', 'max:2000']];
    }
}
