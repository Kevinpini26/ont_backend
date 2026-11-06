#!/usr/bin/env bash
# Lance la suite de tests et échoue si elle a créé, modifié ou supprimé un fichier du stockage réel
# (storage/app). Les tests ont leur propre dossier : voir tests/bootstrap.php.
set -uo pipefail
cd "$(dirname "$0")/.."

instantane() {
    find storage/app -type f -printf '%P\t%s\t%T@\n' | LC_ALL=C sort
}

avant=$(mktemp)
apres=$(mktemp)
ecart=$(mktemp)
trap 'rm -f "$avant" "$apres" "$ecart"' EXIT

instantane > "$avant"
php artisan test "$@"
code=$?
instantane > "$apres"

if ! diff "$avant" "$apres" > "$ecart"; then
    echo "ÉCHEC : la suite de tests a touché le stockage réel ($(grep -c '^[<>]' "$ecart") lignes d'écart)."
    if [ -n "${CONTROLE_STOCKAGE_ECARTS:-}" ]; then
        cp "$ecart" "$CONTROLE_STOCKAGE_ECARTS"
    fi
    grep '^[<>]' "$ecart" | cut -f1 | head -n 20
    exit 1
fi

echo "Stockage réel inchangé par la suite de tests ($(wc -l < "$apres") fichiers contrôlés)."
exit "$code"
