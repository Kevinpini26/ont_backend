<?php

namespace Modules\Courrier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Models\Courrier;

class StoreCourrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Courrier::class);
    }

    /**
     * Le contenu (document TipTap) transite en JSON string quand la requête
     * est multipart (nécessaire dès qu'une pièce jointe accompagne
     * l'envoi) : décodé ici avant validation pour que la règle 'array'
     * s'applique de façon identique, que la requête soit multipart ou JSON.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contenu'))) {
            $this->merge(['contenu' => json_decode($this->input('contenu'), true)]);
        }
    }

    public function rules(): array
    {
        return [
            'objet' => ['required', 'string', 'max:255'],
            'contenu' => ['nullable', 'array'],
            // Numérisation obligatoire à la Réception : un courrier
            // physique entrant n'existe, pour le reste du circuit, que par
            // son scan — contrairement à une direction qui rédige
            // elle-même son contenu (TipTap), sans document physique à
            // numériser (voir posteDeCreation()/config('courrier.poste_creation')).
            'piece_jointe' => [
                Rule::requiredIf(fn () => $this->user()->poste?->value === config('courrier.poste_creation')),
                'nullable',
                'file',
                'max:5120',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
            ],
            'type' => ['required', Rule::enum(CourrierType::class)],
            'direction_origine_id' => ['nullable', 'integer', 'exists:directions,id'],
            'direction_destination_id' => ['nullable', 'integer', 'exists:directions,id'],
            'expediteur_externe_nom' => ['nullable', 'string', 'max:255'],
            'candidat_nom' => [Rule::requiredIf(fn () => $this->input('type') === CourrierType::DEMANDE_STAGE->value), 'nullable', 'string', 'max:255'],
            // Facultatif même pour une demande de stage : un dépôt au
            // guichet n'a pas toujours d'e-mail, contrairement au dépôt en
            // ligne (voir DeposerDemandeStagePublicRequest). Sert de second
            // facteur alternatif au nom pour le suivi public du dossier, et
            // permet d'envoyer l'accusé de réception par e-mail quand il
            // est renseigné (voir CourrierCircuitService::creer()).
            'candidat_email' => ['nullable', 'email', 'max:255'],
            'candidat_contact' => [Rule::requiredIf(fn () => $this->input('type') === CourrierType::DEMANDE_STAGE->value), 'nullable', 'string', 'max:255'],
            'candidat_etablissement' => [Rule::requiredIf(fn () => $this->input('type') === CourrierType::DEMANDE_STAGE->value), 'nullable', 'string', 'max:255'],
            'periode_souhaitee_debut' => [Rule::requiredIf(fn () => $this->input('type') === CourrierType::DEMANDE_STAGE->value), 'nullable', 'date'],
            'periode_souhaitee_fin' => [Rule::requiredIf(fn () => $this->input('type') === CourrierType::DEMANDE_STAGE->value), 'nullable', 'date', 'after:periode_souhaitee_debut'],

            // Métadonnées de la lettre elle-même, distinctes de sa
            // réception — facultatives : un courrier initié par une
            // direction (rédigé directement, pas de lettre physique reçue)
            // n'en a simplement aucune.
            'date_courrier' => ['nullable', 'date'],
            'reference_expediteur' => ['nullable', 'string', 'max:255'],
            'qualite_expediteur' => ['nullable', 'string', 'max:255'],
            'mode_reception' => ['nullable', Rule::enum(ModeReception::class)],
            'nombre_annexes' => ['nullable', 'integer', 'min:0'],

            // Annexes au-delà de la pièce jointe principale — voir
            // Courrier::piecesJointes(). Facultatives, un courrier officiel
            // n'a pas toujours d'annexe distincte de son document principal.
            'pieces_jointes' => ['nullable', 'array', 'max:20'],
            'pieces_jointes.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],

            'degre_urgence' => ['nullable', Rule::enum(DegreUrgence::class)],
            'niveau_confidentialite' => ['nullable', Rule::enum(NiveauConfidentialite::class)],
        ];
    }
}
