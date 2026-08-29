# Politique de sécurité — ONT Backend

## Modèle de menaces retenu

Ce système est une API interne exposée sur Internet pour :
- l'usage quotidien des agents de l'ONT (circuit courrier, gestion des
  stagiaires) via le frontend React, authentifiés par jeton Sanctum ;
- un accès public restreint et non authentifié pour un candidat externe
  (dépôt de demande de stage, dépôt de courrier, suivi de dossier,
  vérification d'attestation).

Les menaces considérées, par ordre de probabilité pour ce contexte :

1. **Moissonnage de données par énumération d'identifiants séquentiels**
   depuis les routes publiques (numéro d'accusé de réception, numéro
   d'attestation). Traité : les deux exigent un second facteur (nom du
   déposant, ou e-mail pour un dépôt en ligne), avec verrouillage par
   numéro après cinq échecs et réponse strictement identique dans tous
   les cas d'échec (voir `Modules/Public/app/Http/Controllers/Api/`).
2. **Bruteforce de mots de passe.** Traité à deux niveaux indépendants :
   limiteur de débit par IP et par e-mail (`throttle:auth`, fenêtre d'une
   minute) et verrouillage de compte après cinq échecs consécutifs
   (quinze minutes, voir `AuthController`).
3. **Élévation de privilèges par accès inter-directions.** Traité par le
   Global Scope Eloquent (`BelongsToDirectionScope`) doublé, pour les
   postes du circuit courrier central, d'un filtrage explicite au niveau
   applicatif (voir `Modules\Stagiaires\Support\VisibiliteStagiairePourCircuitCourrier`) —
   jamais une seule barrière.
4. **Vol de jeton par XSS côté frontend.** Le jeton Sanctum est en
   `sessionStorage` (pas `localStorage`) : ne survit pas à la fermeture
   de l'onglet ni ne se partage entre onglets, mais reste lisible par du
   JavaScript en cas de XSS — une protection complète demanderait un
   cookie `HttpOnly` émis par le backend (non fait, voir le README
   frontend pour le compromis assumé).
5. **Compromission du serveur applicatif lui-même.** Une sauvegarde
   stockée sur ce même serveur ne protège de rien dans ce scénario — voir
   « Sauvegardes » ci-dessous.

Hors périmètre explicitement : déni de service volumétrique (à traiter au
niveau de l'infrastructure/CDN, pas de l'application), sécurité physique
des postes des agents.

## Données personnelles traitées et durée de conservation

| Donnée | Sujets concernés | Table(s) | Conservation |
|---|---|---|---|
| Identité, contact, établissement d'origine | Candidats à un stage | `stagiaires`, `courriers` | Indéfinie tant que le stage est actif ou clôturé avec succès |
| Identité, contact | Candidature de stage **non retenue** (avis DG défavorable) | `courriers` | **12 mois** après l'avis, puis anonymisation automatique (`courrier:anonymiser-candidatures-non-retenues`, planifiée) |
| Pièce d'identité, diplômes, CV | Stagiaires | Disque `local` (jamais public) | Durée du dossier ; accès restreint à la DFP et à l'administrateur (`StagiairePolicy::telechargerDocument()`) |
| Contenu et pièces jointes de courrier | Correspondants internes/externes | `courriers`, disque `local` | Indéfinie (valeur probante administrative) |
| Retour d'expérience de fin de stage | Stagiaires | `stagiaire_retours` | Indéfinie ; strictement confidentiel, jamais visible par la direction d'accueil (`StagiairePolicy::voirRetour()`) |
| Journal d'audit (connexion, actions sensibles) | Agents | `audit_logs` | Indéfinie ; IP et user-agent inclus |

Aucune donnée personnelle n'est envoyée à un service tiers en dehors de
l'e-mail (SMTP configuré par l'ONT) et, si activé, du stockage S3 des
sauvegardes.

## Procédure de signalement

Un problème de sécurité constaté sur ce système doit être signalé
directement à la Direction des Ressources Humaines et de la Logistique
(DRHL) de l'ONT, responsable du système d'information, **jamais** par un
canal public (ticket GitHub, réseau social). Ne pas exploiter la faille
au-delà de ce qui est strictement nécessaire pour la démontrer.

## Réglages obligatoires avant mise en ligne

Une installation locale/de démonstration n'est **pas** sûre pour la
production telle quelle. Avant tout déploiement réel :

- `APP_DEBUG=false` — sinon une erreur applicative affiche la trace
  complète (chemins serveur, requêtes SQL) à n'importe quel visiteur.
- `APP_ENV=production` — active le garde-fou qui refuse de démarrer si
  `BACKUP_DISK` pointe encore vers le disque local (voir
  `Modules\Kernel\Support\BackupDiskProductionGuard`).
- `BACKUP_DISK=backups-s3`, avec de vrais identifiants `BACKUP_S3_*` — une
  sauvegarde sur le même serveur que l'application ne protège de rien en
  cas de panne ou de compromission de ce serveur (voir le garde-fou
  ci-dessus, qui refusera de démarrer sans ce réglage).
- `FRONTEND_URL` positionné sur le domaine réel du frontend, un seul —
  c'est la seule origine autorisée par CORS (`config/cors.php`, pas de
  joker).
- `SANCTUM_TOKEN_EXPIRATION` — 480 minutes (8h) par défaut ; à raccourcir
  si la politique de l'ONT l'exige.
- `AUTH_PASSWORD_RESET_EXPIRE` — 30 minutes par défaut pour un lien de
  réinitialisation de mot de passe.
- Un vrai serveur SMTP configuré (`MAIL_*`) — sans ça, aucun accusé de
  réception, aucune notification, aucun e-mail de réinitialisation de mot
  de passe ne part réellement.
- HTTPS obligatoire en amont (reverse proxy/nginx) — `Strict-Transport-Security`
  n'est envoyé par l'application que sur une requête déjà en HTTPS (voir
  `Modules\Kernel\Http\Middleware\SecurityHeaders`), il ne force rien tout seul.

## Sauvegardes

`php artisan kernel:sauvegarder-base-de-donnees` (planifiée quotidiennement
à 3h) exporte la base vers le disque configuré. `php artisan
kernel:restaurer-base-de-donnees` restaure depuis la sauvegarde la plus
récente (ou un fichier précis), avec confirmation obligatoire dans tous
les environnements. Voir le guide d'installation pour la procédure
complète.
