<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un segment déjà découpé par le navigateur (voir
 * docs/numerisation-courrier.md, import par lot depuis une clé USB) :
 * la lecture du code-barres et la découpe du PDF multi-courriers se font
 * côté client, ce point d'entrée ne reçoit qu'un fichier déjà identifié.
 */
class ImporterLotNumerisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'numero_accuse_reception' => ['required', 'string', 'exists:courriers,numero_accuse_reception'],
            'fichier' => [
                'required', 'file',
                'max:'.config('kernel.numerisation.plafond_ko_courrier'),
                'mimes:pdf', 'mimetypes:application/pdf',
            ],
        ];
    }
}
