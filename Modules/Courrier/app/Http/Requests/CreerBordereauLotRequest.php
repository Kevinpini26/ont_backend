<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Courrier\Models\BordereauLot;

class CreerBordereauLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('creer', BordereauLot::class);
    }

    public function rules(): array
    {
        return [
            'courrier_ids' => ['required', 'array', 'min:1'],
            'courrier_ids.*' => ['integer', 'distinct'],
        ];
    }
}
