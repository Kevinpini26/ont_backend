<?php

namespace Modules\Stagiaires\Enums;

enum DocumentType: string
{
    case LETTRE_STAGE_UNIVERSITE = 'lettre_stage_universite';
    case PIECE_IDENTITE = 'piece_identite';
    case ATTESTATION_INSCRIPTION = 'attestation_inscription';
    case ATTESTATION_STAGE = 'attestation_stage';
    case CV = 'cv';
    case DIPLOME_ETAT = 'diplome_etat';
    case DERNIER_DIPLOME = 'dernier_diplome';
    case LETTRE_DEMANDE_STAGE = 'lettre_demande_stage';
    case PHOTO = 'photo';
    case RAPPORT_FIN_STAGE = 'rapport_fin_stage';
    case NOTE_AFFECTATION = 'note_affectation';
    case CERTIFICAT_FIN_STAGE = 'certificat_fin_stage';
    case ENGAGEMENT_CONFIDENTIALITE = 'engagement_confidentialite';

    public function label(): string
    {
        return match ($this) {
            self::LETTRE_STAGE_UNIVERSITE => "Lettre de stage de l'université",
            self::PIECE_IDENTITE => "Pièce d'identité",
            self::ATTESTATION_INSCRIPTION => "Attestation d'inscription",
            self::ATTESTATION_STAGE => 'Attestation de stage',
            self::CV => 'CV du candidat',
            self::DIPLOME_ETAT => "Diplôme d'État",
            self::DERNIER_DIPLOME => 'Dernier diplôme obtenu',
            self::LETTRE_DEMANDE_STAGE => 'Lettre de demande de stage',
            self::PHOTO => "Photo d'identité",
            self::RAPPORT_FIN_STAGE => 'Rapport de fin de stage',
            self::NOTE_AFFECTATION => "Note d'affectation",
            self::CERTIFICAT_FIN_STAGE => 'Certificat de fin de stage',
            self::ENGAGEMENT_CONFIDENTIALITE => 'Engagement de confidentialité',
        };
    }
}
