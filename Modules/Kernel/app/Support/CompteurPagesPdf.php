<?php

namespace Modules\Kernel\Support;

/**
 * Compte les pages d'un PDF par analyse de sa structure textuelle — jamais
 * en le rendant (aucune extension image sur ce serveur, voir Dockerfile).
 * Heuristique volontairement simple : compte les occurrences de
 * "/Type /Page" en excluant "/Type /Pages" (le nœud racine de l'arbre des
 * pages). Fonctionne pour l'immense majorité des PDF non compressés en
 * flux d'objets ; retourne null plutôt qu'un chiffre faux si rien n'est
 * détecté, à charge de l'appelant de traiter cette incertitude (voir
 * GestionnaireDocumentNumerise, qui n'en fait jamais un motif de rejet).
 */
class CompteurPagesPdf
{
    public static function compter(string $contenuPdf): ?int
    {
        $nombre = preg_match_all('/\/Type\s*\/Page(?!s)\b/', $contenuPdf);

        return $nombre > 0 ? $nombre : null;
    }
}
