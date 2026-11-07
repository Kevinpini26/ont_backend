# Guide d'installation — Système d'information ONT

Destiné à l'équipe informatique de l'Office National du Tourisme. Ce guide
suppose une première installation, sans connaissance préalable de Laravel
ni de Docker au-delà des commandes indiquées ici.

## 1. Prérequis serveur

- Un serveur Linux (Debian/Ubuntu recommandé) avec au moins 2 Go de RAM.
- [Docker](https://docs.docker.com/engine/install/) et le plugin Docker
  Compose (`docker compose version` doit répondre).
- Un nom de domaine pointant vers le serveur (ex. `exemple.cd` pour le
  portail, `api.exemple.cd` pour l'API) — ou deux sous-domaines, ou un seul
  domaine avec un chemin `/api` selon votre choix de reverse proxy.
- Un certificat HTTPS (ce guide suppose un reverse proxy externe — nginx,
  Caddy ou Traefik — qui termine le TLS et transmet en HTTP interne aux
  ports `8000` (API) et `80` (portail) exposés par Docker Compose ; sa
  configuration précise dépend de l'outil choisi et n'est pas couverte
  ici).
- Un compte SMTP pour l'envoi d'e-mails (accusés de réception, notifications,
  réinitialisation de mot de passe) — sans lui, ces e-mails ne partent pas.

## 2. Installation pas à pas

```bash
# 1. Cloner les deux dépôts côte à côte (obligatoire : le frontend est
#    construit depuis ../frontend par docker-compose.yml du backend).
git clone <url-du-dépôt-backend> backend
git clone <url-du-dépôt-frontend> frontend
cd backend

# 2. Préparer la configuration de production.
cp .env.docker.production.example .env.docker.production
# Éditer .env.docker.production : voir SECURITY.md pour la liste complète
# des réglages obligatoires (APP_DEBUG=false, BACKUP_DISK=backups-s3,
# FRONTEND_URL, identifiants SMTP réels...).

# 3. Générer une clé d'application (APP_KEY) — à copier dans
#    .env.docker.production, jamais laissée vide.
# Sans artisan ni vendor (absents d'un clone neuf) : même format que `key:generate --show`.
docker run --rm php:8.3-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
# Copier la valeur affichée (base64:...) dans APP_KEY= du fichier .env.docker.production.

# 4. Démarrer l'ensemble (base de données, API, worker, planificateur, portail).
# --env-file est obligatoire (pas une option de confort) : sans lui, Compose
# résout les ${DB_PASSWORD} etc. du docker-compose.yml depuis un éventuel
# fichier .env local (développement natif), jamais depuis
# .env.docker.production — voir l'en-tête de docker-compose.yml.
docker compose --env-file .env.docker.production up -d --build

# 5. Vérifier que tout est démarré.
docker compose ps
```

Les migrations de base de données s'exécutent automatiquement au démarrage
du conteneur `backend` (voir `docker/entrypoint.production.sh`) — rien à
lancer à la main pour une première installation.

### Variables à renseigner pour la production

| Variable | Rôle |
|---|---|
| `KERNEL_ADMIN_INITIAL_PASSWORD` | Mot de passe initial de `admin@ont.cd`, créé par le seed s'il n'existe pas (à changer à la première connexion) |
| `TRUSTED_PROXIES` | Adresse ou plage du reverse proxy HTTPS. Vide par défaut : aucun en-tête `X-Forwarded-*` n'est cru |
| `API_LIMITE_CONNECTE` | Appels API par minute et par utilisateur connecté (240 par défaut) |
| `API_ORIGIN` (conteneur frontend) | Origine de l'API si elle est sur un autre domaine, pour la CSP (vide sinon) |

Limites d'envoi : nginx 64 Mo par requête, PHP 21 Mo par fichier et 64 Mo par requête
(`docker/php/ont-limites.ini`). Le plus gros fichier accepté par l'application est de 20 Mo.

### Lecture des scans (OCR)

L'image de production contient `poppler-utils` et `tesseract-ocr` (français) depuis le lot 6, mais la
lecture des scans est **désactivée par défaut**. Pour l'activer : `OCR_ACTIVE=true`, puis redémarrer la
file d'attente. Mesuré sur un serveur à 8 cœurs : environ 2,4 secondes par page A4 (200 dpi) ; ces outils
ajoutent environ 86 Mo à l'image. La recherche dans les scans respecte les droits d'accès et la
confidentialité (voir `RechercheScansConfidentialiteTest`).

## 3. Créer le premier compte administrateur

Aucun compte n'existe après une première installation (contrairement à
l'environnement de démonstration locale). Le créer directement en base :

```bash
docker compose exec backend php artisan tinker --execute="
Modules\Kernel\Models\User::create([
    'name' => 'Administrateur ONT',
    'email' => 'admin@exemple.cd',
    'password' => 'UnMotDePasseRobuste#2026',
    'role' => Modules\Kernel\Enums\UserRole::ADMINISTRATEUR,
    'email_verified_at' => now(),
]);
echo 'Compte créé.';
"
```

Se connecter avec ce compte sur le portail, puis créer les comptes des
agents depuis **Administration → Utilisateurs** — chaque compte ainsi créé
devra changer son mot de passe à la première connexion (c'est le
comportement attendu, voir `EnsureMotDePasseAJour`).

## 4. Planificateur et file d'attente

Contrairement à un déploiement Laravel classique (cron système +
`schedule:run` chaque minute), ce système utilise `schedule:work` et
`queue:work` comme **processus continus**, déjà démarrés par
`docker compose up` (services `scheduler` et `queue-worker`, voir
`docker-compose.yml`) — rien à configurer côté système d'exploitation du
serveur hôte.

Tâches planifiées ajoutées au lot 6 : `courrier:alerter-classeur` (chaque heure : courriers au classeur
d'attente au-delà du délai en heures ouvrées). Les relances existantes comptent désormais en heures ouvrées
(calendrier réglable dans « Paramètres de l'organisation »).

Vérifier qu'ils tournent : `docker compose logs -f scheduler queue-worker`.
Si l'un des deux plante, `docker compose restart scheduler` (ou
`queue-worker`) le relance — `restart: unless-stopped` dans
`docker-compose.yml` le referait de toute façon automatiquement après un
crash ou un redémarrage du serveur.

## 5. Configuration SMTP réelle

Renseigner dans `.env.docker.production` : `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`.
Puis `docker compose --env-file .env.docker.production up -d --build backend queue-worker` pour appliquer.
Tester l'envoi réel :

```bash
docker compose exec backend php artisan tinker --execute="
Illuminate\Support\Facades\Mail::raw('Test technique.', function (\$m) {
    \$m->to('votre-adresse@exemple.cd')->subject('Test SMTP ONT');
});
echo 'Envoyé (vérifier la boîte de réception).';
"
```

## 6. Sauvegarde et restauration

La sauvegarde quotidienne (3h du matin) est automatique une fois
`BACKUP_DISK=backups-s3` correctement configuré (voir SECURITY.md) —
aucune action requise en fonctionnement normal.

**Sauvegarde manuelle immédiate** :

```bash
docker compose exec backend php artisan kernel:sauvegarder-base-de-donnees
```

**Restauration** (écrase entièrement la base actuelle — confirmation
demandée) :

```bash
docker compose exec backend php artisan kernel:restaurer-base-de-donnees
# Ou un fichier précis plutôt que le plus récent :
docker compose exec backend php artisan kernel:restaurer-base-de-donnees database/ont-2026-06-15_030000.sql.gz
```

**Fichiers (scans, PDF officiels, pièces jointes, documents des stagiaires)** : archivés chaque
jour à 3h30 (`kernel:sauvegarder-fichiers`) dans `fichiers/` sur le même disque de sauvegarde.
Une base restaurée sans ses fichiers est inutilisable : restaurez toujours les deux, de la même nuit.

**Restaurer les fichiers.** Il n'existe pas de commande dédiée : suivez exactement cette procédure.

1. Arrêtez l'application et les workers, pour que personne n'écrive pendant la restauration.
2. Récupérez l'archive depuis le disque de sauvegarde (`fichiers/` sur le disque `BACKUP_DISK`).
3. Ouvrez un terminal avec le compte de l'application : `docker compose exec backend sh`.
4. Adaptez les lignes `ARCHIVE` et `STOCKAGE`, puis exécutez le bloc suivant :

<!-- debut-procedure-restauration-fichiers -->
```sh
set -e
ARCHIVE=/chemin/ont-fichiers-AAAA-MM-JJ_HHMMSS.tar.gz
STOCKAGE=/var/www/html/storage/app/private

tar -tzf "$ARCHIVE" > /dev/null
NOUVEAU=$(mktemp -d "$STOCKAGE.restauration.XXXXXX")
tar -xzf "$ARCHIVE" -C "$NOUVEAU"
(cd "$NOUVEAU" && find . -type f -exec sha256sum {} + | sort -k2) > "$NOUVEAU.sha256"
if [ -d "$STOCKAGE" ]; then
  mv "$STOCKAGE" "$STOCKAGE.avant-restauration-$(date +%Y%m%d%H%M%S)"
fi
mv "$NOUVEAU" "$STOCKAGE"
(cd "$STOCKAGE" && sha256sum -c "$NOUVEAU.sha256" > /dev/null)
echo "Restauration réussie : $(wc -l < "$NOUVEAU.sha256") fichiers contrôlés."
```
<!-- fin-procedure-restauration-fichiers -->

Le dossier présent avant l'opération n'est jamais effacé : il est conservé sous le nom
`private.avant-restauration-…`. Supprimez-le vous-même une fois l'application contrôlée. Le bloc
s'arrête à la première erreur, sans avoir touché au dossier actuel si l'archive est illisible.

5. Si vous disposez d'une liste d'empreintes faite avant l'incident, comparez-la au fichier
   `….sha256` : c'est la seule preuve que le contenu n'a pas changé depuis la sauvegarde.

**Vérifier qu'une sauvegarde est réellement restaurable** (automatique chaque dimanche à 5h, ou à la
demande). La sauvegarde la plus récente est restaurée dans une base **jetable**, contrôlée, puis
supprimée ; la base de l'application n'est jamais touchée.

```bash
docker compose exec backend php artisan kernel:verifier-restauration
```

Si le compte PostgreSQL de l'application n'a pas le droit `CREATEDB` (recommandé), un administrateur
crée une seule fois une base vide dédiée, puis on la désigne :

```sql
CREATE DATABASE ont_verif_restauration_hebdo OWNER <utilisateur_de_l_application>;
```

```bash
docker compose exec backend php artisan kernel:verifier-restauration --base=ont_verif_restauration_hebdo
```

La commande refuse toute base qui ne porte pas le préfixe `ont_verif_restauration_` ou qui n'est
pas vide, et la vide à nouveau après chaque vérification.

## 7. Que faire quand un compte est compromis

1. Se connecter avec un compte administrateur.
2. **Administration → Utilisateurs**, ouvrir le compte concerné.
3. Réinitialiser son mot de passe (l'utilisateur devra le changer à sa
   prochaine connexion) **et** révoquer ses jetons actifs — les deux
   actions sont nécessaires : changer le mot de passe n'invalide pas à lui
   seul une session déjà ouverte ailleurs.
4. Si le compte a été verrouillé par erreur (cinq échecs de connexion
   consécutifs), le même écran permet un déverrouillage manuel immédiat
   plutôt que d'attendre les quinze minutes de verrouillage automatique.
5. Consulter **Administration → Journal d'audit**, filtré sur ce compte,
   pour identifier les actions effectuées pendant la période suspectée de
   compromission.

## 8. Dépannage — sept erreurs fréquentes

| Symptôme | Cause probable | Solution |
|---|---|---|
| Le conteneur `backend` boucle en redémarrage avec `password authentication failed for user` dans ses logs | `docker compose up` a été lancé sans `--env-file .env.docker.production` : Compose a résolu `${DB_PASSWORD}` depuis un `.env` local de développement (s'il en existe un dans ce dossier), pas depuis `.env.docker.production` — la base a alors été initialisée avec un mot de passe différent de celui transmis au backend | Toujours démarrer avec `docker compose --env-file .env.docker.production up -d --build` (voir §2) ; si la base a déjà été créée avec le mauvais mot de passe, `docker compose down -v` puis relancer avec la bonne commande (perte des données — normal seulement en tout premier démarrage) |
| `docker compose up` échoue avec « BACKUP_DISK=backups-local en production » | `.env.docker.production` n'a pas `BACKUP_DISK=backups-s3` avec de vrais identifiants | Renseigner les variables `BACKUP_S3_*` (voir §5 de SECURITY.md) |
| `docker compose up` échoue avec « MAIL_MAILER=log en production » | `.env.docker.production` a laissé `MAIL_MAILER=log` (valeur de développement) | Renseigner `MAIL_MAILER=smtp` (ou `ses`, `postmark`...) et les identifiants réels (voir §5) |
| Le portail public s'affiche mais aucune action ne fonctionne (erreurs réseau) | `VITE_API_BASE_URL` ne pointe pas vers la bonne adresse de l'API, ou CORS refuse l'origine | Vérifier `VITE_API_BASE_URL` (build du frontend) et `FRONTEND_URL` (backend, CORS) — les deux doivent correspondre au domaine réel |
| Aucun e-mail n'est jamais envoyé (accusé de réception, notification) | Configuration SMTP absente ou incorrecte, ou `queue-worker` arrêté | Vérifier `docker compose logs queue-worker` et les identifiants SMTP (§5) |
| Une tâche planifiée (sauvegarde, relance, alerte d'échéance) ne s'exécute jamais | Le service `scheduler` n'est pas démarré ou a crashé | `docker compose ps` puis `docker compose restart scheduler` |
| Erreur 500 sur toute requête après une mise à jour du code | Cache de configuration/routes obsolète (`config:cache`/`route:cache` posé au démarrage du conteneur, pas régénéré automatiquement à chaud) | `docker compose --env-file .env.docker.production up -d --build backend` (relance l'entrypoint, qui régénère les caches) |
