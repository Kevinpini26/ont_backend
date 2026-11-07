# ONT — Backend

Système d'information de l'Office National du Tourisme de la RDC : API REST pure (Laravel 13, Sanctum, PostgreSQL), organisée en modules indépendants via [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules).

Aucune vue Blade n'est servie : le backend n'expose que du JSON. Le frontend (React + Vite, dossier `frontend/`) consomme cette API, y compris l'éditeur de texte riche TipTap pour la rédaction des courriers — le backend ne fait que stocker/retourner le contenu structuré (JSON), sans rendu HTML serveur.

## Modules

| Module      | Rôle |
|-------------|------|
| `Kernel`    | Utilisateurs, directions, authentification, Global Scope par direction, policies transverses |
| `Courrier`  | Circuit courrier (machine à états), notes de circuit (annotations typées), relecture, numérotation, événement `CourrierStageAvisFavorable` |
| `Stagiaires`| Cycle de vie du stagiaire, affectation, présences, documents, double évaluation, attestation PDF |
| `Public`    | Points d'entrée publics (sans authentification) : suivi de dossier, vérification d'attestation, dépôt de demande de stage, liens à usage unique |

Le code applicatif vit entièrement sous `Modules/<Nom>/app`, jamais dans `app/` (qui ne contient que le câblage framework : `Controller` de base, `bootstrap/app.php`, exceptions globales).

## Installation

Environnement de développement complet (base de données, backend, frontend) en une commande : `docker compose up` depuis la racine du dépôt parent (voir le `docker-compose.yml` à cette racine, et son en-tête pour le détail). Déploiement en production : `INSTALLATION.md` à la racine de ce dépôt, puis `PRODUCTION_CHECKLIST.md` pour la vérification finale avant ouverture réelle.

Installation native (hors Docker), pour travailler sur ce dépôt seul :

```bash
composer install
cp .env.example .env   # puis renseigner DB_* (PostgreSQL)
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Un compte administrateur `admin@ont.cd` est créé par le seeder. En local et en test, son mot de passe de démonstration est connu (voir `AdminUserSeeder`). Partout ailleurs, il n'est créé que s'il n'existe pas, jamais réinitialisé, avec le mot de passe fourni par `KERNEL_ADMIN_INITIAL_PASSWORD` (sinon un mot de passe aléatoire inutilisable) et un changement de mot de passe obligatoire à la première connexion. Voir `SECURITY.md`. En environnement local (`APP_ENV=local`), le seeder crée aussi des comptes de démonstration (un par poste du circuit courrier, DFP, un responsable par direction — mot de passe unique (voir `AdminUserSeeder`), voir `Modules\Kernel\Database\Seeders\DemoAccountsSeeder`) ainsi que des courriers de démonstration jusqu'à l'étape « projet à rédiger » (les étapes suivantes se démontrent en direct) et un stagiaire académique/professionnel à chaque étape du cycle de vie.

### Base de données

PostgreSQL (paramétrable dans `.env` : `DB_CONNECTION=pgsql`). Les tests utilisent une base séparée (`ont_testing`, voir `phpunit.xml`).

### Tests

```bash
php artisan test
```

Plus de 1 090 tests couvrant : authentification, verrouillage/désactivation de compte, changement/réinitialisation de mot de passe, passation de poste unique, filtrage par direction, journal d'audit et supervision système (Kernel) ; progression stricte du circuit courrier, bordereau de transmission et décharge obligatoire à chaque étape, refus de signature sans relecture validée, atomicité sous concurrence, tableau de bord statistique et notifications e-mail tracées (Courrier) ; création automatique de la fiche stagiaire, double évaluation et calcul de la note finale, présences, quotas d'affectation et tableau de bord DFP (Stagiaires) ; vérification publique d'un dossier avec second facteur anti-énumération, dépôt de demande de stage en ligne (Public).

```bash
./vendor/bin/pint --test      # style de code (PSR-12 + règles Laravel)
./vendor/bin/phpstan analyse  # analyse statique, Larastan niveau 5
```

### Génération du PDF d'attestation

Le module Stagiaires utilise `barryvdh/laravel-dompdf`. Le PDF est stocké sur le disque `local` (`storage/app/attestations/`).

### Prévisualiser un email

```bash
php artisan mail:previsualiser --lister       # liste les 7 Mailables du projet
php artisan mail:previsualiser <cle>           # génère storage/app/previsualisations-email/<cle>.html
```

Données fictives, dans une transaction toujours annulée (voir `Modules\Kernel\Console\PrevisualiserEmailCommand`) : aucune écriture réelle. Utile pour relire le gabarit commun (`resources/views/vendor/mail/html/`, en-tête et pied ONT partagés par les 7 Mailables) après une modification.

## Authentification

Jetons Sanctum (`Authorization: Bearer <token>`). Toutes les routes sauf `POST /api/v1/auth/login`, les routes de mot de passe oublié et le module `Public` exigent ce header. Les jetons expirent après `SANCTUM_TOKEN_EXPIRATION` minutes (8h par défaut) — la réponse de connexion expose aussi `expires_at` pour que le frontend planifie sa propre expiration. Un administrateur peut révoquer manuellement les jetons d'un compte (`DELETE /api/v1/users/{id}/tokens`).

Un compte est verrouillé 15 minutes après 5 échecs de connexion consécutifs (indépendant du rate limiting par IP) ; un administrateur peut déverrouiller immédiatement (`POST /api/v1/users/{id}/deverrouiller`). Un compte créé par un administrateur doit changer son mot de passe à sa première connexion (`doit_changer_mot_de_passe`) ; toute autre action est bloquée (423) jusqu'à `POST /api/v1/auth/mot-de-passe/changer`. Réinitialisation via e-mail : `POST /api/v1/auth/mot-de-passe/oublie` puis `POST /api/v1/auth/mot-de-passe/reinitialiser` (Password Broker natif de Laravel, réponse générique dans tous les cas pour ne jamais confirmer l'existence d'un compte).

Voir `SECURITY.md` à la racine du dépôt pour le détail des mesures de sécurité (rate limiting, CORS, en-têtes de sécurité, journal d'audit, politique de mot de passe...).

## Rôles et postes (module Kernel)

- **Rôles** : `administrateur`, `agent_dfp`, `responsable_direction`, `agent_circuit_courrier`.
- **Postes** (uniquement pour `agent_circuit_courrier`) : `reception`, `dga`, `assistant_1` (Ass1), `assistant_2` (Ass2), `assistant_dga` (Ass.Dga), `dg`, `secretariat_1`, `secretariat_2`. Deux postes historiques subsistent dans l'enum (`protocole`, `assistant_protocole`) uniquement pour hydrater d'anciens dossiers — plus jamais attribués à une nouvelle affectation, voir `Poste::estHistorique()`.
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
| GET | `/api/v1/mes-actions` | ✔ | Accueil « Ce que j'ai à faire » : actions de l'agent, triées par urgence puis retard |
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
| GET  | `/api/v1/sites` | ✔ (tous) | Référentiel des sites (siège, représentations provinciales) — préparation multi-site, pas encore exploitée par les directions |
| POST | `/api/v1/sites` | ✔ admin | Créer un site — pas de modification ni de suppression (portée volontairement minimale) |
| GET | `/api/v1/dg-disponibilite` | ✔ | Disponibilité déclarée de la DG (garde l'intérim dynamique de la DGA) |
| POST | `/api/v1/dg-disponibilite` | ✔ dg | Déclare la DG indisponible/disponible |
| GET | `/api/v1/rapports/periodique` | ✔ admin ou dfp | Génère le rapport PDF mensuel/annuel destiné à la tutelle |
| GET | `/api/v1/users` | ✔ admin | Liste paginée des comptes, filtrable (`recherche`, `role`, `poste`, `direction_id`, `actif`, `verrouille`), triable, `par_page` |
| POST | `/api/v1/users` | ✔ admin | Crée un compte par invitation (`role`, `poste?`, `direction_id?`) — aucun mot de passe saisi, un lien à usage unique est envoyé |
| GET | `/api/v1/users/{id}` | ✔ admin ou soi-même | Détail d'un compte, dernière connexion et sessions actives |
| PUT/PATCH | `/api/v1/users/{id}` | ✔ admin | Modifier un compte |
| DELETE | `/api/v1/users/{id}` | ✔ admin | Supprimer un compte sans trace d'audit (sinon : désactiver) |
| POST | `/api/v1/users/{id}/desactiver` | ✔ admin | Désactive un compte (bloque la connexion, révoque ses jetons) |
| POST | `/api/v1/users/{id}/reactiver` | ✔ admin | Réactive un compte désactivé |
| DELETE | `/api/v1/users/{id}/tokens` | ✔ admin | Révoque tous les jetons Sanctum du compte (compte compromis) |
| DELETE | `/api/v1/users/{id}/tokens/{jeton}` | ✔ admin | Révoque une session précise (un seul appareil) |
| POST | `/api/v1/postes-uniques/passation` | ✔ admin | Passation d'un poste unique (DG, DGA, Secrétariat 01/02, Réception) : désactive l'ancien titulaire, active le nouveau |
| POST | `/api/v1/users/{id}/deverrouiller` | ✔ admin | Lève un verrouillage pour échecs de connexion avant les 15 minutes automatiques |
| GET | `/api/v1/audit-logs` | ✔ admin | Journal d'audit, filtrable par `action`, `user_id`, `sujet_type`, `sujet_id`, `recherche`, `depuis`, `jusqu_a` |
| GET | `/api/v1/admin/tableau-de-bord` | ✔ admin | Comptes actifs/verrouillés, échecs d'e-mail du jour, jobs en échec, dernière sauvegarde, dernières actions sensibles |
| GET | `/api/v1/supervision` | ✔ admin | File d'attente, planificateur, emails, dernière sauvegarde, espace disque |
| GET/POST | `/api/v1/supervision/jobs-en-echec[/{uuid}/relancer]` | ✔ admin | Jobs en échec (`failed_jobs`) et relance (`queue:retry`) |
| GET/POST | `/api/v1/supervision/emails-en-echec[/{id}/renvoyer]` | ✔ admin | Notifications e-mail en échec et renvoi via leur job d'origine |
| POST | `/api/v1/supervision/email-test` | ✔ admin | Envoie un e-mail de test (mécanisme tracé standard) à l'appelant |
| GET | `/api/v1/parametres` | ✔ admin | Paramètres de l'organisation (valeur actuelle et par défaut) |
| PUT | `/api/v1/parametres/{cle}` | ✔ admin | Modifie un paramètre (audité) — voir `Modules\Kernel\Models\Setting` |

---

## Endpoints — Module Courrier

Le Protocole n'existe plus dans l'organisation actuelle (`au_protocole`/`en_circuit_hierarchique` restent dans l'enum `CourrierStatut` uniquement pour hydrater d'anciens dossiers) : un courrier entrant qui nécessite l'avis de la DG suit désormais `recu → en_attente_tri → (en_attente_avis_dg | en_attente_classeur → en_attente_avis_dg) → en_dispatch | projet_a_rediger → projet_a_valider → en_attente_signature → signe → enregistre`. Le tri (Secrétariat 01) oriente par degré d'urgence (urgent/très urgent part directement à la DG, normal patiente au classeur d'attente) ; un avis DG « réservé » boucle vers `retour_reception` plutôt que d'avancer. Un courrier sans avis DG requis (entre deux directions) emprunte le **circuit court**, sans tri ni avis. Un courrier **initié par la DG** (`POST .../initier-dg`) suit une troisième variante : `en_attente_validation_dg → en_relecture → signe → enregistre`. Le poste habilité à chaque étape (et les transitions dynamiques — intérim DGA, renvoi pour correction par le relecteur désigné) est piloté par `config('courrier.circuit_transitions')`, point d'extension `Modules\Courrier\Contracts\CircuitTransitionRules` (implémentation : `ConfigCircuitTransitionRules`).

**Courrier sortant** (réponse à un courrier entrant, ou courrier sortant direct via `POST .../initier-sortant`) : `projet_a_rediger → projet_a_valider → en_attente_signature → signe → envoye`, avec choix du mode de remise (courriel, retrait physique, ou les deux) après signature.

**Bordereau de transmission et décharge** : chaque transition produit un bordereau (`Modules\Courrier\Models\CourrierTransition`) à l'attention d'un destinataire précis (poste, ou personne désignée pour la relecture). Le destinataire doit explicitement accuser réception (`POST .../accuser-reception`, ou en lot via `POST /bordereaux-lot`) avant de pouvoir agir à son tour — voir `Courrier::enTransit()`. Toute tentative d'action sur un dossier encore « en transit » renvoie **422**.

La logique applicative (orchestration des transitions) est répartie en plusieurs services spécialisés sous `Modules\Courrier\Services`, tous invoqués via l'unique façade `CourrierCircuitService` (aucun appelant externe ne connaît ce découpage interne) : `CourrierReceptionService` (création, accusé de réception, prise en charge des documents autonomes), `CourrierTriDispatchService` (tri, avis DG, dispatch vers une direction), `CourrierReponseService` (rédaction, relecture, signature), `CourrierEnvoiService` (choix du mode de sortie, envoi, remise).

| Méthode | URL | Poste requis | Description |
|---|---|---|---|
| GET | `/api/v1/courriers` | ✔ (filtré par direction) | Liste paginée, filtrable par `statut`, `direction_origine_id`, `direction_destination_id`, `recherche` |
| POST | `/api/v1/courriers` | `reception` | Création d'un courrier entrant (`objet`, `type`, `piece_jointe?`, champs `candidat_*` si `type=demande_stage`) → statut `recu` + génère `numero_accuse_reception` |
| POST | `/api/v1/courriers/initier-dg` | `secretariat_1` | Courrier sortant initié par la DG sur instruction préalable (`instructions-courrier-dg`) → `en_attente_validation_dg` |
| POST | `/api/v1/courriers/initier-sortant` | poste habilité à initier une réponse | Courrier sortant direct, sans courrier entrant déclencheur |
| GET | `/api/v1/courriers/{id}` | ✔ (filtré) | Détail, avec le fil complet des bordereaux (`transitions`) |
| POST | `/api/v1/courriers/{id}/accuser-reception` | destinataire du bordereau courant | Décharge le bordereau en cours, débloque l'action suivante |
| POST | `/api/v1/courriers/{id}/transmettre-tri` | `secretariat_1` | `recu → en_attente_tri` |
| POST | `/api/v1/courriers/{id}/transmettre-avis-dg` | `secretariat_1` | `en_attente_tri → en_attente_avis_dg` (degré urgent/très urgent) ou `→ en_attente_classeur` (normal) |
| POST | `/api/v1/courriers/{id}/transmettre-depuis-classeur` | `secretariat_1` | `en_attente_classeur → en_attente_avis_dg`, à l'initiative de SEC1 |
| POST | `/api/v1/courriers/{id}/transmettre-sec1` | `reception` | `retour_reception → en_attente_tri`, incrémente le tour du dossier |
| POST | `/api/v1/courriers/{id}/requalifier-urgence` | `dg` | Corrige le degré d'urgence après le tri initial |
| POST | `/api/v1/courriers/{id}/rendre-avis` | `dg` (ou `dga` en intérim si la DG s'est déclarée indisponible) | `{avis_dg: favorable\|defavorable\|reserve, avis_dg_commentaire?}` → `en_dispatch` (favorable sur courrier imputé), `projet_a_rediger` (favorable/défavorable), ou `retour_reception` (réservé). Favorable + `type=demande_stage` émet `CourrierStageAvisFavorable`. |
| POST | `/api/v1/courriers/{id}/renvoyer-au-tri` | `dg`/`dga` | Mauvaise orientation : renvoi au Secrétariat 01 sans incrémenter le tour |
| POST | `/api/v1/courriers/{id}/imputer` | poste habilité | Impute le courrier à une direction principale (avant dispatch) |
| POST | `/api/v1/courriers/{id}/dispatcher-direction` | `secretariat_2` | `en_dispatch → chez_direction`, remet le courrier imputé au secrétariat de la direction |
| POST | `/api/v1/courriers/{id}/soumettre-projet-reponse` | assistant actif (Ass1/Ass2) | `{projet_reponse_contenu (JSON TipTap), relecteur_id}` → `projet_a_rediger → projet_a_valider` |
| POST | `/api/v1/courriers/{id}/renvoyer-pour-correction` | relecteur désigné | `{observation}` → `projet_a_valider → projet_a_rediger` |
| POST | `/api/v1/courriers/{id}/valider-relecture` | relecteur désigné uniquement | `{commentaire?}` — ne change pas le statut, débloque la signature |
| POST | `/api/v1/courriers/{id}/valider-pour-signature` | `dg` | `projet_a_valider → en_attente_signature`, attribue le numéro de départ et prépare le PDF à signer |
| GET | `/api/v1/courriers/{id}/pdf-a-signer` | ✔ (filtré) | Télécharge le PDF à imprimer et faire signer physiquement |
| POST | `/api/v1/courriers/{id}/scan-signe` | `dg` (titulaire ou DGA en intérim) | Téléverse le scan signé → `en_attente_signature → signe` |
| POST | `/api/v1/courriers/{id}/signer` | `dg` | `en_relecture → signe` (circuit « initié par DG ») — **refusé (422) si la relecture n'a pas été validée** |
| POST | `/api/v1/courriers/{id}/enregistrer` | `secretariat_2` | `{classification: interne\|externe, note_technique?, accuse_reception_partenaire?}` → `signe → enregistre`, génère `numero_enregistrement` |
| POST | `/api/v1/courriers/{id}/mode-sortie` | `secretariat_2` | Choisit le canal de sortie d'un courrier sortant signé (courriel, retrait physique, les deux) |
| POST | `/api/v1/courriers/{id}/envoyer-par-courriel` \| `/rendre-disponible-retrait` \| `/confirmer-remise-physique` | `secretariat_2` | Suit chaque canal de sortie séparément jusqu'à la remise effective |
| POST | `/api/v1/courriers/{id}/initier-reponse` \| `/enregistrer-remise` | poste habilité | Réponse directe à un courrier reçu (hors workflow sortant complet) et remise physique legacy |
| POST \| GET | `/api/v1/courriers/{id}/sortir-original` \| `/restituer-original` | ✔ (filtré) | Sortie/retour de l'original papier pour consultation, distinct d'une version numérisée (`docs/numerisation-courrier.md`, Lot 5) |
| GET | `/api/v1/courriers/originaux-empruntes` | ✔ (filtré) | Liste des originaux actuellement sortis, toutes directions confondues |
| GET | `/api/v1/courriers/{id}/feuille-couverture` \| `/courriers/feuilles-couverture-lot` | `reception` | Feuille(s) de couverture à code-barres CODE_128, à imprimer avant numérisation |
| POST | `/api/v1/courriers/import-lot` | `reception` | Reçoit un segment de PDF déjà découpé côté client (lecture de code-barres et découpage faits dans le navigateur) |
| GET | `/api/v1/courriers/a-numeriser` | `reception` | Rattrapage des dossiers dont la numérisation officielle manque encore |
| GET | `/api/v1/courriers/{id}/numerisations/{document}` | ✔ (filtré) | Télécharge une version numérisée précise |
| GET | `/api/v1/courriers/{id}/pdf` \| `/piece-jointe` \| `/pieces-jointes/{piece}` \| `/lettre-stage` \| `/pieces/{piece}` \| `/imprimer` | ✔ (filtré) | Téléchargements associés au dossier (PDF signé, pièces jointes, lettre de stage, pièce du candidat, fiche imprimable) |
| GET | `/api/v1/courriers/{id}/annotations` | ✔ (filtré) | Liste des annotations horodatées |
| POST | `/api/v1/courriers/{id}/annotations` | ✔ (filtré) | `{contenu, type?, destinataire_poste?, destinataire_direction_id?}` : note de circuit (`instruction`, `observation`, `rappel` personnel, `urgent`) |
| POST | `/api/v1/courriers/{id}/annotations/{annotation}/correction` | auteur de la note | `{contenu}` : crée une nouvelle note qui corrige l'ancienne (jamais de modification) |
| POST | `/api/v1/courriers/{id}/annotations/{annotation}/lecture` | ✔ (filtré) | Marque la note comme lue (la lecture d'une instruction est tracée) |
| DELETE | `/api/v1/courriers/{id}/annotations/{annotation}` | auteur du rappel | Retire un rappel personnel ; toute autre note du circuit est refusée (403) |
| GET | `/api/v1/annotations/instructions-non-lues` | ✔ (filtré) | Instructions non lues qui visent le poste ou la direction de l'appelant |
| GET | `/api/v1/courriers/registre` \| `/export` | postes du circuit central, admin | Registre PDF (arrivée/départ sur une période) et export CSV filtré |
| GET | `/api/v1/courriers/statistiques` \| `/en-souffrance` \| `/justesse-tri` | ✔ (filtré) | Tableau de bord par étape, dossiers en retard par rapport au délai indicatif de leur statut, taux de justesse du tri |
| GET | `/api/v1/courriers/statistiques-dg` | `dg`/`dga` | Tableau de bord dédié à la DG (dossiers en attente d'avis, délais) |
| GET | `/api/v1/courriers/statistiques-direction` | responsable de direction, `agent_dfp`, admin | Tableau de bord d'une direction précise |

Toute tentative de saut d'étape ou de mauvais poste renvoie **422** (`TransitionNonAutoriseeException`) si le poste correspond à l'étape courante mais vise le mauvais statut cible, ou **403** si le poste ne correspond pas du tout à l'étape courante (policy `CourrierPolicy@transmettre`, filtre grossier — la vérification précise reste dans les services ci-dessus).

Sous-systèmes documentés séparément (trop volumineux pour ce tableau, chacun avec ses propres routes, statuts et policies) :
- **Documents autonomes** (`documents-produits-direction`, `traitements-direction`) : une direction initie elle-même un document (note, courrier) sans courrier entrant déclencheur, avec contrôle qualité Réception/SEC1/DG selon la destination.
- **Dispatch et classement** (`dispatchs`, `classements-documents`, `dossiers`) : exécution des décisions institutionnelles après avis DG (vers une direction, vers le classement) et archivage des dossiers physiques.
- **Missions documentaires** (`missions-documentaires`, `projets-reponse`) : délégation nominative de la préparation d'une réponse à un assistant.
- **Numérisation** (`docs/numerisation-courrier.md`) : feuilles de couverture à code-barres, import par lot (clé USB), originaux physiques.

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
| GET | `/api/v1/stagiaires/{id}/badge` | ✔ (filtré) | Badge d'identité PDF (matricule requis, donc uniquement une fois affecté) |
| GET | `/api/v1/stagiaires/{id}/imprimer` | ✔ (filtré) | Fiche imprimable de l'état courant du dossier |
| GET \| POST | `/api/v1/stagiaires/{id}/suivis` | ✔ (filtré) / direction d'accueil, maître de stage désigné, `agent_dfp` | Fiche de suivi périodique — notes datées sur le déroulement du stage |
| POST | `/api/v1/stagiaires/{id}/presences/reconciliation` | `agent_dfp` | `{entrees: [{date, heure_arrivee?, heure_depart?}], forcer?}` — réconciliation en lot de présences saisies hors ligne ; une entrée qui contredit une présence déjà enregistrée est signalée en conflit et non appliquée, sauf `forcer` |
| GET | `/api/v1/stagiaires/export` | `agent_dfp`, admin | Export CSV filtré (`direction_id?`, `statut?`) |
| GET | `/api/v1/stagiaires/rapport-annuel` | `agent_dfp`, admin | Bilan PDF des stages pour une année civile (`{annee}`) |
| GET | `/api/v1/archives/annuelle` | `agent_dfp`, admin | Archive ZIP consolidée (registres courrier arrivée/départ + rapport annuel des stages), à destination de la tutelle ou de l'INACO |
| GET | `/api/v1/etablissements-formation` | ✔ (tous) | Référentiel des établissements de formation, utile au remplissage du dossier |
| POST | `/api/v1/etablissements-formation` | `agent_dfp` | Alimente le référentiel |
| GET | `/api/v1/stagiaires/statistiques` \| `/alertes` \| `/en-souffrance` | `agent_dfp` ou admin (`statistiques` aussi ouvert à une direction, sur son propre périmètre) | Tableau de bord DFP, échéances proches (10 jours), dossiers en retard par rapport au délai indicatif de leur statut |
| GET/POST | `/api/v1/stagiaires/disponibilite-demandes` | ✔ / `agent_dfp` | Consulte/active l'ouverture des dépôts publics de demande de stage |

Alerte d'échéance : commande planifiée quotidienne `stagiaires:verifier-echeances` (notifie DFP + direction d'accueil 10 jours avant `date_fin_stage`).

**Tableaux de répartition** (`tableaux-repartition`, documenté séparément — trop volumineux pour ce tableau) : circuit de validation collégial (Réception, DG/DGA, administrateur) pour l'affectation d'un lot de stagiaires sur une période, distinct de l'affectation individuelle ci-dessus.

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
| POST | `/api/v1/public/liens/{token}/rapport-stage` | Soumission du rapport de stage par le stagiaire. |
| POST | `/api/v1/public/liens/{token}/signer-engagement-confidentialite` | Signature de l'engagement de confidentialité, sans compte. |
| GET | `/api/v1/public/capture/{token}` \| POST | Capture mobile de numérisation (dépôt de photos depuis le téléphone du candidat, voir le jeton généré par `POST /courriers/{id}/jeton-capture`). |
| GET | `/api/v1/public/reponses/{courrier}/pdf` | Téléchargement de la réponse officielle via un lien signé à durée limitée (`Setting::valeur('courrier.lien_ttl_minutes')`, voir « Paramètres de l'organisation »), envoyé au demandeur externe. |
| GET | `/api/v1/public/statistiques` | Chiffres publics (volumétrie, délais moyens) pour la page d'accueil institutionnelle. |
| GET | `/api/v1/public/mention-information` | Texte de la mention d'information RGPD-like affichée avant tout dépôt public. |

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
| `Modules\Kernel\Contracts\NotificationService` | Seul point de contact autorisé avec les façades Mail/Notification | `LaravelNotificationService` + `Modules\Kernel\Jobs\EnvoyerNotificationEmailJob` (suivi dans `notifications_email`, 3 tentatives, alerte interne si échec définitif) |
| `Modules\Courrier\Contracts\AccuseReceptionGuichetGenerator` | Fiche imprimable de secours (courrier enregistré au guichet sans e-mail) | `DompdfAccuseReceptionGuichetGenerator` |
| `Modules\Kernel\Models\Setting` (pas une interface, mais le même rôle) | Paramètres de l'organisation modifiables en production (nom, contacts, formats, délais d'alerte, TTL des liens, taille max des pièces jointes) | Table `settings` clé/valeur, cache invalidé à l'écriture, repli sur `config()` si jamais modifié — voir l'écran « Paramètres de l'organisation » |

Chaque interface est bindée dans le `register()` du `ServiceProvider` de son module — aucune logique métier codée en dur dans les contrôleurs.
