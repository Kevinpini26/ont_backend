<?php

namespace Modules\Public\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SoumettreRapportStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'mimes'/'mimetypes' évalués à partir du contenu réel du
            // fichier (finfo), jamais de l'extension déclarée par le
            // client — même garde que UploadDocumentRequest côté circuit
            // interne.
            'fichier' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf',
                'mimetypes:application/pdf',
            ],
        ];
    }
}
