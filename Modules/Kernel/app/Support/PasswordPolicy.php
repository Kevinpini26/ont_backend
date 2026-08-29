<?php

namespace Modules\Kernel\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Règle de mot de passe unique, utilisée partout où un mot de passe est
 * défini (création/modification de compte par un administrateur,
 * changement volontaire, réinitialisation) — jamais redéfinie localement,
 * pour ne jamais laisser un point d'entrée retomber discrètement sur une
 * exigence plus faible que les autres.
 */
class PasswordPolicy
{
    public static function regle(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->uncompromised();
    }
}
