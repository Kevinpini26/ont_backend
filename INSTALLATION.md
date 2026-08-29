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
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php artisan key:generate --show
# Copier la valeur affichée (base64:...) dans APP_KEY= du fichier .env.docker.production.

# 4. Démarrer l'ensemble (base de données, API, worker, planificateur, portail).
docker compose up -d --build

# 5. Vérifier que tout est démarré.
docker compose ps
```

Les migrations de base de données s'exécutent automatiquement au démarrage
du conteneur `backend` (voir `docker/entrypoint.production.sh`) — rien à
lancer à la main pour une première installation.

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

Vérifier qu'ils tournent : `docker compose logs -f scheduler queue-worker`.
Si l'un des deux plante, `docker compose restart scheduler` (ou
`queue-worker`) le relance — `restart: unless-stopped` dans
`docker-compose.yml` le referait de toute façon automatiquement après un
crash ou un redémarrage du serveur.

## 5. Configuration SMTP réelle

Renseigner dans `.env.docker.production` : `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`.
Puis `docker compose up -d --build backend queue-worker` pour appliquer.
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

**Vérifier régulièrement** qu'une restauration fonctionne réellement (pas
seulement que la sauvegarde s'exécute sans erreur) — idéalement sur un
serveur de test, jamais la première fois lors d'un incident réel.

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

## 8. Dépannage — cinq erreurs fréquentes

| Symptôme | Cause probable | Solution |
|---|---|---|
| `docker compose up` échoue avec « BACKUP_DISK=backups-local en production » | `.env.docker.production` n'a pas `BACKUP_DISK=backups-s3` avec de vrais identifiants | Renseigner les variables `BACKUP_S3_*` (voir §5 de SECURITY.md) |
| Le portail public s'affiche mais aucune action ne fonctionne (erreurs réseau) | `VITE_API_BASE_URL` ne pointe pas vers la bonne adresse de l'API, ou CORS refuse l'origine | Vérifier `VITE_API_BASE_URL` (build du frontend) et `FRONTEND_URL` (backend, CORS) — les deux doivent correspondre au domaine réel |
| Aucun e-mail n'est jamais envoyé (accusé de réception, notification) | Configuration SMTP absente ou incorrecte, ou `queue-worker` arrêté | Vérifier `docker compose logs queue-worker` et les identifiants SMTP (§5) |
| Une tâche planifiée (sauvegarde, relance, alerte d'échéance) ne s'exécute jamais | Le service `scheduler` n'est pas démarré ou a crashé | `docker compose ps` puis `docker compose restart scheduler` |
| Erreur 500 sur toute requête après une mise à jour du code | Cache de configuration/routes obsolète (`config:cache`/`route:cache` posé au démarrage du conteneur, pas régénéré automatiquement à chaud) | `docker compose up -d --build backend` (relance l'entrypoint, qui régénère les caches) |
