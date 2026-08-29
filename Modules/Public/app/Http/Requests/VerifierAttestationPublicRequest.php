<?php

namespace Modules\Public\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifierAttestationPublicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'numero' => ['required', 'string', 'max:50'],
            'nom' => ['required', 'string', 'max:255'],
        ];
    }
}
