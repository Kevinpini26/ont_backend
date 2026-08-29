# ONT — Backend

Système d'information de l'Office National du Tourisme de la RDC : API REST pure (Laravel 13, Sanctum, PostgreSQL), organisée en modules indépendants via [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules).

Aucune vue Blade n'est servie : le backend n'expose que du JSON. Le frontend (React + Vite, dossier `frontend/`) consomme cette API, y compris l'éditeur de texte riche TipTap pour la rédaction des courriers — le backend ne fait que stocker/retourner le contenu structuré (JSON), sans rendu HTML serveur.

## Modules

| Module      | Rôle |
|-------------|------|
| `Kernel`    | Utilisateurs, directions, authentification, Global Scope par direction, policies transverses |
| `Courrier`  | Circuit courrier (machine à états), annotations, relecture, numérotation, événement `CourrierStageAvisFavorable` |
| `Stagiaires`| Cycle de vie du stagiaire, affectation, présences, documents, double évaluation, attestation PDF |
| `Public`    | Points d'entrée publics (sans authentification) : suivi de dossier, vérification d'attestation, dépôt de demande de stage, liens à usage unique |

Le code applicatif vit entièrement sous `Modules/<Nom>/app`, jamais dans `app/` (qui ne contient que le câblage framework : `Controller` de base, `bootstrap/app.php`, exceptions globales).

## Installation

Environnement de développement complet (base de données, backend, frontend) en une commande : `docker compose up` depuis la racine du dépôt parent (voir le `docker-compose.yml` à cette racine, et son en-tête pour le détail). Déploiement en production : `INSTALLATION.md` à la racine de ce dépôt.

Installation native (hors Docker), pour travailler sur ce dépôt seul :

```bash
composer install
cp .env.example .env   # puis renseigner DB_* (PostgreSQL)
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Un compte administrateur est créé par le seeder : `admin@ont.cd` / `ChangeMoi#ONT2026` — **à changer immédiatement après le premier déploiement réel**, voir `SECURITY.md` à la racine du dépôt. En environnement local (`APP_ENV=local`), le seeder crée aussi des comptes de démonstration (un par poste du circuit courrier, DFP, un responsable par direction — mot de passe unique `Demo#ONT2026`, voir `Modules\Kernel\Database\Seeders\DemoAccountsSeeder`) ainsi qu'un dossier courrier à chaque étape du circuit et un stagiaire académique/professionnel à chaque étape du cycle de vie.

### Base de données

PostgreSQL (paramétrable dans `.env` : `DB_CONNECTION=pgsql`). Les tests utilisent une base séparée (`ont_testing`, voir `phpunit.xml`).

### Tests

```bash
php artisan test
```

Plus de 300 tests (dont une quarantaine de tests unitaires purs, sans base de données) couvrant : authentification, verrouillage de compte, changement/réinitialisation de mot de passe, filtrage par direction et journal d'audit (Kernel) ; progression stricte du circuit courrier, bordereau de transmission et décharge obligatoire à chaque étape, refus de signature sans relecture validée, atomicité sous concurrence et tableau de bord statistique (Courrier) ; création automatique de la fiche stagiaire, double évaluation et calcul de la note finale, présences, quotas d'affectation et tableau de bord DFP (Stagiaires) ; vérification publique d'un dossier avec second facteur anti-énumération, dépôt de demande de stage en ligne (Public).

```bash
./vendor/bin/pint --test      # style de code (PSR-12 + règles Laravel)
./vendor/bin/phpstan analyse  # analyse statique, Larastan niveau 5
```

### Génération du PDF d'attestation

Le module Stagiaires utilise `barryvdh/laravel-dompdf`. Le PDF est stocké sur le disque `local` (`storage/app/attestations/`).

## Authentification

Jetons Sanctum (`Authorization: Bearer <token>`). Toutes les routes sauf `POST /api/v1/auth/login`, les routes de mot de passe oublié et le module `Public` exigent ce header. Les jetons expirent après `SANCTUM_TOKEN_EXPIRATION` minutes (8h par défaut) — la réponse de connexion expose aussi `expires_at` pour que le frontend planifie sa propre expiration. Un administrateur peut révoquer manuellement les jetons d'un compte (`DELETE /api/v1/users/{id}/tokens`).

Un compte est verrouillé 15 minutes après 5 échecs de connexion consécutifs (indépendant du rate limiting par IP) ; un administrateur peut déverrouiller immédiatement (`POST /api/v1/users/{id}/deverrouiller`). Un compte créé par un administrateur doit changer son mot de passe à sa première connexion (`doit_changer_mot_de_passe`) ; toute autre action est bloquée (423) jusqu'à `POST /api/v1/auth/mot-de-passe/changer`. Réinitialisation via e-mail : `POST /api/v1/auth/mot-de-passe/oublie` puis `POST /api/v1/auth/mot-de-passe/reinitialiser` (Password Broker natif de Laravel, réponse générique dans tous les cas pour ne jamais confirmer l'existence d'un compte).

Voir `SECURITY.md` à la racine du dépôt pour le détail des mesures de sécurité (rate limiting, CORS, en-têtes de sécurité, journal d'audit, politique de mot de passe...).

## Rôles et postes (module Kernel)

- **Rôles** : `administrateur`, `agent_dfp`, `responsable_direction`, `agent_circuit_courrier`.
- **Postes** (uniquement pour `agent_circuit_courrier`) : `reception`, `protocole`, `dga`, `assistant_protocole` (Ass.P), `assistant_1` (Ass1), `assistant_2` (Ass2), `assistant_dga` (Ass.Dga), `dg`, `secretariat_1`, `secretariat_2`.
- Tout utilisateur sauf `administrateur` et `agent_dfp` est rattaché à une `direction_id`.

### Global Scope par direction

`Modules\Kernel\Scopes\DirectionScope` (et son équivalent bidirectionnel `Modules\Courrier\Scopes\CourrierDirectionScope`) filtrent automatiquement les enregistrements selon `direction_id` de l'utilisateur connecté. Le contournement (voir toutes les directions) s'applique à :
- `administrateur` et `agent_dfp` (toujours) ;
- `agent_circuit_courrier` dont le poste figure dans `config('kernel.circuit_courrier_central_postes')` (par défaut : tous les postes du circuit central, car le circuit courrier est par nature transverse aux directions).

La règle de contournement est un point d'extension : `Modules\Kernel\Contracts\DirectionScopeBypassResolver`, bindée par défaut sur `DefaultDirectionScopeBypassResolver`.

---

## Endpoints — Module Kernel

Détail exhaustif (schémas de requête/réponse, codes d'erreur) : `docs/openapi.yaml`, servi sur `GET /api/v1/docs/openapi.yaml` (ouvert en développement, réservé aux administrateurs en production).

| Méthode | URL | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/auth/login` | — | `{email, password, device_name?}` → `{user, token, expires_at}` |
| POST | `/api/v1/auth/logout` | ✔ | Révoque le jeton courant |
| GET  | `/api/v1/auth/me` | ✔ | Profil de l'utilisateur connecté |
| POST | `/api/v1/auth/mot-de-passe/changer` | ✔ | Change son propre mot de passe, révoque les autres jetons du compte |
| POST | `/api/v1/auth/mot-de-passe/oublie` | — | Demande un lien de réinitialisation (réponse générique) |
| POST | `/api/v1/auth/mot-de-passe/reinitialiser` | — | Réinitialise via le lien reçu par e-mail |
| GET | `/api/v1/agents-circuit-courrier` | ✔ (tous) | Liste minimale (id, nom, poste) des agents du circuit courrier — sert à désigner un relecteur |
| GET | `/api/v1/notifications` | ✔ | Notifications de l'utilisateur (`data`, `non_lues`) |
| POST | `/api/v1/notifications/{id}/marquer-lu` | ✔ | Marque une notification comme lue |
| POST | `/api/v1/notifications/marquer-toutes-lues` | ✔ | Marque toutes les notifications comme lues |
| GET | `/api/v1/notifications/compteurs` | ✔ | Compteurs pour les badges de la sidebar |
| POST | `/api/v1/notifications/marquer-consulte` | ✔ | Marque un compteur comme consulté |
| GET  | `/api/v1/directions` | ✔ (tous) | Liste des 8 directions |
| GET  | `/api/v1/directions/{id}` | ✔ (tous) | Détail d'une direction |
| POST | `/api/v1/directions` | ✔ admin | Créer une direction |
| PUT/PATCH | `/api/v1/directions/{id}` | ✔ admin | Modifier une direction |
| DELETE | `/api/v1/directions/{id}` | ✔ admin | Supprimer une direction |
| GET | `/api/v1/dg-disponibilite` | ✔ | Disponibilité déclarée de la DG (garde l'intérim dynamique de la DGA) |
| POST | `/api/v1/dg-disponibilite` | ✔ dg | Déclare la DG indisponible/disponible |
| GET | `/api/v1/rapports/periodique` | ✔ admin ou dfp | Génère le rapport PDF mensuel/annuel destiné à la tutelle |
| GET | `/api/v1/users` | ✔ admin | Liste paginée des comptes |
| POST | `/api/v1/users` | ✔ admin | Créer un compte (`role`, `poste?`, `direction_id?`) — `doit_changer_mot_de_passe` forcé à vrai |
| GET | `/api/v1/users/{id}` | ✔ admin ou soi-même | Détail d'un compte |
| PUT/PATCH | `/api/v1/users/{id}` | ✔ admin | Modifier un compte |
| DELETE | `/api/v1/users/{id}` | ✔ admin | Supprimer un compte |
| DELETE | `/api/v1/users/{id}/tokens` | ✔ admin | Révoque tous les jetons Sanctum du compte (compte compromis) |
| POST | `/api/v1/users/{id}/deverrouiller` | ✔ admin | Lève un verrouillage pour échecs de connexion avant les 15 minutes automatiques |
| GET | `/api/v1/audit-logs` | ✔ admin | Journal d'audit (connexions, signatures, affectations, notations), filtrable par `action`, `user_id`, `depuis` |

---

## Endpoints — Module Courrier

Circuit complet (celui d'une demande de stage, ou d'un courrier destiné à la DG) : `recu → au_protocole → en_attente_avis_dg → projet_reponse_en_cours → en_relecture → signe → enregistre`. Un courrier envoyé directement entre deux directions (jamais une demande de stage) emprunte le **circuit court** : `recu → en_attente_avis_dg → ...` (saute `au_protocole`). Un courrier **initié par la DG** (`POST .../initier-dg`) suit une troisième variante : `recu → en_attente_validation_dg → en_relecture → ...`. Le poste habilité à chaque étape est piloté par `config('courrier.circuit_transitions')`, point d'extension `Modules\Courrier\Contracts\CircuitTransitionRules`.

**Bordereau de transmission et décharge** : chaque transition produit un bordereau (`Modules\Courrier\Models\CourrierTransition`) à l'attention d'un destinataire précis (poste, ou personne désignée pour la relecture). Le destinataire doit explicitement accuser réception (`POST .../accuser-reception`) avant de pouvoir agir à son tour — voir `Courrier::enTransit()`. Toute tentative d'action sur un dossier encore « en transit » renvoie **422**.

| Méthode | URL | Poste requis | Description |
|---|---|---|---|
| GET | `/api/v1/courriers` | ✔ (filtré par direction) | Liste paginée, filtrable par `statut`, `direction_origine_id`, `direction_destination_id`, `recherche` |
| POST | `/api/v1/courriers` | `reception` **ou** `responsable_direction` | Création (`objet`, `type`, `direction_destination_id?`, `piece_jointe?`, champs `candidat_*` si `type=demande_stage`) → statut `recu` + génère `numero_accuse_reception`. Si l'auteur est un `responsable_direction`, `direction_origine_id` est forcé à sa propre direction. |
| POST | `/api/v1/courriers/initier-dg` | `secretariat_1` | Courrier sortant initié par la DG (objet + contenu + relecteur en une seule action) → `recu → en_attente_validation_dg` |
| GET | `/api/v1/courriers/{id}` | ✔ (filtré) | Détail, avec le fil complet des bordereaux (`transitions`) |
| POST | `/api/v1/courriers/{id}/accuser-reception` | destinataire du bordereau courant | Décharge le bordereau en cours, débloque l'action suivante |
| POST | `/api/v1/courriers/{id}/transmettre-protocole` | `protocole` | `recu → au_protocole` (circuit complet uniquement) |
| POST | `/api/v1/courriers/{id}/valider-avant-diffusion` | `dg` | `en_attente_validation_dg → en_relecture` (circuit « initié par DG » uniquement) |
| POST | `/api/v1/courriers/{id}/transmettre-avis-dg` | `protocole`, ou la direction d'origine (circuit court) | `→ en_attente_avis_dg` |
| POST | `/api/v1/courriers/{id}/rendre-avis` | `dg` (ou `dga` en intérim si la DG s'est déclarée indisponible) | `{avis_dg: favorable\|defavorable\|reserve, avis_dg_commentaire?}` → `en_attente_avis_dg → projet_reponse_en_cours`. Si `avis_dg=favorable` et `type=demande_stage`, émet `CourrierStageAvisFavorable`. |
| POST | `/api/v1/courriers/{id}/soumettre-projet-reponse` | `secretariat_1` | `{projet_reponse_contenu (JSON TipTap), relecteur_id}` → `projet_reponse_en_cours → en_relecture` |
| POST | `/api/v1/courriers/{id}/valider-relecture` | relecteur désigné uniquement | `{commentaire?}` — ne change pas le statut, débloque la signature |
| POST | `/api/v1/courriers/{id}/signer` | `dg` | `en_relecture → signe` — **refusé (422) si la relecture n'a pas été validée** |
| POST | `/api/v1/courriers/{id}/enregistrer` | `secretariat_2` | `{classification: interne\|externe, note_technique?, accuse_reception_partenaire?}` → `signe → enregistre`, génère `numero_enregistrement` |
| GET | `/api/v1/courriers/{id}/pdf` \| `/piece-jointe` \| `/lettre-stage` \| `/pieces/{piece}` | ✔ (filtré) | Téléchargements associés au dossier (PDF signé, pièce jointe, lettre de stage, pièce du candidat) |
| GET | `/api/v1/courriers/{id}/annotations` | ✔ (filtré) | Liste des annotations horodatées |
| POST | `/api/v1/courriers/{id}/annotations` | ✔ (filtré) | `{contenu}` |
| GET | `/api/v1/courriers/statistiques` | ✔ (filtré) | Tableau de bord : volumétrie par étape, en attente de relecture, temps moyen de traitement (historique `courrier_transitions`) |
| GET | `/api/v1/courriers/statistiques-dg` | `dg`/`dga` | Tableau de bord dédié à la DG (dossiers en attente d'avis, délais) |
| GET | `/api/v1/courriers/statistiques-direction` | responsable de direction, `agent_dfp`, admin | Tableau de bord d'une direction précise |

Toute tentative de saut d'étape ou de mauvais poste renvoie **422** (`TransitionNonAutoriseeException`) si le poste correspond à l'étape courante mais vise le mauvais statut cible, ou **403** si le poste ne correspond pas du tout à l'étape courante (policy `CourrierPolicy@transmettre`).

---

## Endpoints — Module Stagiaires

Statuts : `dossier_recu → en_attente_affectation → affecte → stage_en_cours → evaluation_en_cours → cloture`. Deux types de stage (`academique`, `professionnel`), chacun avec sa propre grille d'évaluation officielle (`Modules\Stagiaires\Support\GrilleEvaluation` / `GrilleEvaluationProfessionnelle`, point d'extension via `StagiaireTypeStage::classeGrille()`).

La fiche est créée **automatiquement** (aucune route de création directe) par `Modules\Stagiaires\Listeners\CreerFicheStagiaireDepuisCourrier`, à l'écoute de `CourrierStageAvisFavorable` — ou par `POST /api/v1/admin/stagiaires/import-historique` (import en masse, réservé à l'administrateur) pour les dossiers antérieurs à la mise en service.

| Méthode | URL | Rôle requis | Description |
|---|---|---|---|
| GET | `/api/v1/stagiaires` | ✔ (filtré) | Liste, filtrable par `direction_id`, `statut`, `type_stage`, `en_cours=1`, `recherche` |
| GET | `/api/v1/stagiaires/{id}` | ✔ (filtré) | Détail |
| POST | `/api/v1/stagiaires/{id}/examiner-dossier` | `agent_dfp` | `dossier_recu → en_attente_affectation` |
| POST | `/api/v1/stagiaires/{id}/affecter` | `agent_dfp` | `{direction_id, forcer?, justification?}` → `en_attente_affectation → affecte`, refusé (422) si le quota de la direction est atteint sans `forcer` |
| POST | `/api/v1/stagiaires/{id}/reaffecter` | `agent_dfp` | `{direction_id, justification}` — change la direction d'accueil en cours de stage, justification toujours obligatoire |
| POST | `/api/v1/stagiaires/{id}/valider-arrivee` | `agent_dfp` | `{date_debut_stage, date_fin_stage?}` → `affecte → stage_en_cours`, génère la convention de stage |
| POST | `/api/v1/stagiaires/{id}/modifier-dates` \| `/prolonger` | `agent_dfp` | Corrige les dates (académique) ou prolonge le stage avec motif tracé (professionnel) |
| POST | `/api/v1/stagiaires/{id}/objectifs` \| `/informations-complementaires` | `agent_dfp` | Complète le dossier (objectifs du stage, filière, maître de stage...) |
| POST | `/api/v1/stagiaires/{id}/convention/signer-direction` | responsable de la direction d'accueil | Signe la convention (le stagiaire la signe via un lien public à usage unique, hors authentification) |
| GET | `/api/v1/stagiaires/{id}/convention/telecharger` | ✔ (filtré) | Télécharge la convention générée |
| POST | `/api/v1/stagiaires/{id}/terminer-stage` | `agent_dfp` ou responsable de la direction d'accueil | `stage_en_cours → evaluation_en_cours` |
| POST | `/api/v1/stagiaires/{id}/ouvrir-periode-evaluation` | `agent_dfp` | Donne à la direction l'accès à son formulaire d'évaluation |
| POST | `/api/v1/stagiaires/{id}/evaluer-direction` | responsable de la direction d'accueil correspondante | `{grille}` (grille complète du type de stage) — le total est toujours recalculé côté serveur |
| POST | `/api/v1/stagiaires/{id}/evaluer-dfp` | `agent_dfp` | `{grille}` — dès que les deux évaluations sont renseignées (quel que soit l'ordre), `note_finale` est calculée (point d'extension `Modules\Stagiaires\Contracts\CalculateurNoteFinale`, moyenne par défaut), le statut passe à `cloture` et le PDF d'attestation est généré |
| GET | `/api/v1/stagiaires/{id}/retour` | `agent_dfp` uniquement | Retour d'expérience du stagiaire (jamais visible par la direction d'accueil) |
| GET | `/api/v1/stagiaires/{id}/presences` | ✔ (filtré) | Liste des présences |
| POST | `/api/v1/stagiaires/{id}/presences` | `agent_dfp` | `{date, heure_arrivee?, heure_depart?}` — upsert sur le jour, refusé un week-end |
| DELETE | `/api/v1/stagiaires/{id}/presences/{date}` | `agent_dfp` | Retire une présence saisie par erreur |
| GET | `/api/v1/stagiaires/{id}/documents` | ✔ (filtré) | Liste des documents |
| POST | `/api/v1/stagiaires/{id}/documents` | `agent_dfp` ou responsable de la direction d'accueil | Upload multipart `{type, fichier}` |
| GET | `/api/v1/stagiaires/{id}/documents/{docId}/telecharger` | ✔ (filtré, selon le type de pièce) | Télécharge un document — pièce d'identité/diplômes/CV réservés à la DFP et l'administrateur |
| GET | `/api/v1/stagiaires/statistiques` \| `/alertes` | `agent_dfp` ou admin (`statistiques` aussi ouvert à une direction, sur son propre périmètre) | Tableau de bord DFP et échéances proches (10 jours) |
| GET/POST | `/api/v1/stagiaires/disponibilite-demandes` | ✔ / `agent_dfp` | Consulte/active l'ouverture des dépôts publics de demande de stage |

Alerte d'échéance : commande planifiée quotidienne `stagiaires:verifier-echeances` (notifie DFP + direction d'accueil 10 jours avant `date_fin_stage`).

---

## Endpoints — Module Public

Aucune route de ce module n'exige d'authentification — c'est justement pourquoi chacune est conçue pour résister à l'énumération : un numéro ou un jeton seul ne suffit jamais à obtenir une donnée personnelle.

| Méthode | URL | Description |
|---|---|---|
| POST | `/api/v1/public/dossiers/verifier` | `{numero, nom}` — suivi d'un dossier. Le nom sert de second facteur (comparaison insensible à la casse/aux accents/à l'ordre des mots, `Modules\Public\Support\NomComparateur`) ; numéro inconnu, nom incorrect ou numéro verrouillé après 5 échecs renvoient la **même** réponse 404 générique. |
| GET | `/api/v1/public/attestations/token/{token}` | Vérifie une attestation via le jeton (32 caractères, non énumérable) imprimé en QR code — aucun second facteur nécessaire. |
| POST | `/api/v1/public/attestations/verifier` | `{numero, nom}` — voie de secours pour les attestations émises avant l'introduction du jeton, même second facteur que la vérification de dossier. |
| POST | `/api/v1/public/demandes-stage` | Dépôt en ligne d'une demande de stage (upload multipart de la lettre et, pour un stage professionnel, du CV) — actif seulement si `disponibilite-demandes-stage` est ouvert côté DFP. |
| GET | `/api/v1/public/disponibilite-demandes-stage` | Indique si le dépôt en ligne est actuellement ouvert. |
| POST | `/api/v1/public/courriers-externes` | Dépôt d'un courrier externe (partenaire sans compte). |
| GET | `/api/v1/public/liens/{token}` | Résout un lien public à usage unique (signature de convention, retour d'expérience) — jeton opaque, jamais l'identifiant interne du dossier. |
| GET | `/api/v1/public/liens/{token}/convention.pdf` | Télécharge la convention associée au lien. |
| POST | `/api/v1/public/liens/{token}/signer-convention` | Signature de la convention par le stagiaire, sans compte. |
| POST | `/api/v1/public/liens/{token}/retour` | Soumission du retour d'expérience par le stagiaire, une seule fois. |

Aucune de ces routes ne renvoie jamais les annotations internes, l'avis DG ou la note technique d'un courrier.

---

## Points d'extension (SOLID)

| Interface | Rôle | Implémentation par défaut |
|---|---|---|
| `Modules\Kernel\Contracts\DirectionScopeBypassResolver` | Qui contourne le filtrage par direction | `DefaultDirectionScopeBypassResolver` (config-driven) |
| `Modules\Courrier\Contracts\CircuitTransitionRules` | Ordre des statuts + poste habilité par étape | `ConfigCircuitTransitionRules` (`config/courrier.php`) |
| `Modules\Courrier\Contracts\NumeroGenerator` / `SequenceGenerator` | Génération des numéros (AR, enregistrement) | `DefaultNumeroGenerator` / `DatabaseSequenceGenerator` (upsert atomique PostgreSQL) |
| `Modules\Stagiaires\Contracts\AffectationRules` | Directions éligibles à l'affectation | `ActiveDirectionsAffectationRules` (directions `actif=true`) |
| `Modules\Stagiaires\Contracts\CalculateurNoteFinale` | Critère de calcul de la note finale | `MoyenneCalculateurNoteFinale` |
| `Modules\Stagiaires\Contracts\AttestationGenerator` | Génération du PDF d'attestation | `DompdfAttestationGenerator` |

Chaque interface est bindée dans le `register()` du `ServiceProvider` de son module — aucune logique métier codée en dur dans les contrôleurs.
