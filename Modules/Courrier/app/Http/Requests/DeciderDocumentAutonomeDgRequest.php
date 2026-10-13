<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Models\DocumentProduitDirection;

class DeciderDocumentAutonomeDgRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deciderAutonomeDg', $this->route('document'));
    }

    public function rules(): array
    {
        $document = $this->route('document');

        return [
            'direction_id' => ['required', 'integer', Rule::exists('directions', 'id')
                ->where('actif', true)
                ->where('est_operationnelle', true)],
            'motif_reorientation' => [
                Rule::requiredIf(fn (): bool => $document instanceof DocumentProduitDirection
                    && (int) $this->input('direction_id') !== (int) $document->direction_destination_souhaitee_id),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
