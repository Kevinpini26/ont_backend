#!/usr/bin/env bash
# Démarre le serveur de répétition, uniquement sur la base jetable et un stockage temporaire.
# Contrôle préalable : tout écart arrête AVANT le démarrage, avant la moindre écriture.
#   DB_DATABASE=ont_repetition_finale LARAVEL_STORAGE_PATH=/chemin/hors/storage scripts/repetition.sh
#   --verifier-seulement : fait le contrôle et s'arrête (utilisé par les tests).
set -euo pipefail
cd "$(dirname "$0")/.."

arret() { echo "ARRÊT : $1" >&2; exit 1; }

BASE_AUTORISEE=ont_repetition_finale
base="${DB_DATABASE:-}"
stockage="${LARAVEL_STORAGE_PATH:-}"

[ "$base" = "$BASE_AUTORISEE" ] || arret "la base doit être ${BASE_AUTORISEE} (reçu : « ${base} »)."
[ -n "$stockage" ] || arret "LARAVEL_STORAGE_PATH est vide : le stockage réel serait utilisé."

reel=$(realpath -m storage)
cible=$(realpath -m "$stockage")
case "$cible/" in
    "$reel"/*) arret "le stockage « ${cible} » est dans le stockage réel (${reel})." ;;
esac

echo "Contrôle réussi : base ${base}, stockage ${cible}"
[ "${1:-}" = "--verifier-seulement" ] && exit 0

for dossier in app/private app/public app/backups framework/cache/data framework/sessions framework/views framework/testing logs; do
    mkdir -p "$cible/$dossier"
done

export DB_DATABASE="$base" LARAVEL_STORAGE_PATH="$cible" APP_ENV=local MAIL_MAILER=log QUEUE_CONNECTION=sync
exec php -d variables_order=EGPCS -S 127.0.0.1:"${PORT:-8010}" -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
