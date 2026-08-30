<?php

namespace Modules\Stagiaires\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Stagiaires\Enums\DocumentType;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererDocument', $this->route('stagiaire'));
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                DocumentType::LETTRE_STAGE_UNIVERSITE->value,
                DocumentType::PIECE_IDENTITE->value,
                DocumentType::ATTESTATION_INSCRIPTION->value,
                DocumentType::CV->value,
                DocumentType::DIPLOME_ETAT->value,
                DocumentType::DERNIER_DIPLOME->value,
                DocumentType::LETTRE_DEMANDE_STAGE->value,
                DocumentType::PHOTO->value,
            ])],
            // 'mimes' et 'mimetypes' sont tous deux évalués par Laravel à
            // partir du contenu réel du fichier (finfo), jamais de
            // l'extension déclarée par le client : un exécutable renommé
            // en .pdf est rejeté par les deux règles.
            'fichier' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
            ],
        ];
    }
}
