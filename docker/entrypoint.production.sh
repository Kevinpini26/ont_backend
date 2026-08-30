#!/bin/sh
set -e

# La découverte des paquets (bootstrap/cache/packages.php et services.php)
# démarre Laravel en entier — jamais faite à la construction de l'image
# (voir Dockerfile.production, --no-scripts sur composer dump-autoload) :
# ici, l'environnement réel du conteneur (.env.docker.production) est
# effectivement chargé.
echo "[entrypoint] Découverte des paquets…"
php artisan package:discover --ansi

# config:cache/route:cache dépendent des variables d'environnement réelles
# (DB_HOST, FRONTEND_URL...), connues seulement au démarrage du conteneur,
# jamais à la construction de l'image (qui doit rester indépendante de
# l'environnement cible — même image pour la démo et pour la production).
echo "[entrypoint] Mise en cache de la configuration et des routes…"
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "$1" = "php-fpm" ]; then
    echo "[entrypoint] Migrations (php artisan migrate --force)…"
    php artisan migrate --force
fi

exec "$@"
