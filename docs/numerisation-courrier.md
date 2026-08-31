# Numérisation du courrier physique

Contexte et contraintes vérifiées dans le code avant toute décision :

- `StoreCourrierRequest::piece_jointe` est déjà obligatoire dès que l'agent
  occupe `config('courrier.poste_creation')` (la Réception) — le principe
  « un courrier physique n'existe pour le circuit que par son scan » était
  déjà posé.
- Le Dockerfile exclut délibérément `gd`/`imagick` (le serveur de
  production visé ne les a pas non plus — c'est pour ça que les QR codes
  sortent en SVG, voir `EndroidQrCodeService`). **Aucune préparation
  d'image ne peut se faire côté serveur** : recadrage, redressement,
  niveaux de gris, compression se font tous côté client (navigateur).
- Un navigateur ne peut pas piloter un scanner : la capture passe soit par
  l'appareil photo du téléphone (`<input capture="environment">`), soit
  par un fichier déjà produit ailleurs (copieur, scanner à plat) et
  déposé.

## Ordre retenu (revu après clarification)

Lot 2 (modèle de données) → Lot 1 (capture mobile) → Lot 3a (feuille de
couverture) + import par lot USB → Lot 5 (lien fichier/papier) → Lot 4
(recherche plein texte, OCR).

Le Lot 4 est délibérément en dernier : c'est le plus dépendant d'une
infrastructure serveur incertaine (OCR), le reste fonctionne sans lui.

Le Lot 3 « historique » a été scindé en deux, à la demande de l'ONT :
- **3a, la feuille de couverture** : construite sans condition, ne dépend
  d'aucun matériel — sert avec un téléphone (lecture du code-barres dans
  le navigateur, plus besoin du QR affiché à l'écran de bureau) et sur
  papier comme chemise de classement.
- **3b, l'ingestion automatique depuis un dossier réseau/mail** : mise de
  côté tant que l'ONT n'a pas confirmé son matériel. Dans les bureaux
  publics congolais, un copieur multifonction existe généralement, mais
  ni le scan-vers-dossier-réseau ni le scan-vers-mail n'y sont configurés
  en pratique (absence de partage réseau, absence de serveur SMTP local) —
  le **scan vers clé USB**, lui, fonctionne toujours : l'agent scanne sa
  liasse, branche la clé sur son poste, dépose le PDF par glisser-déposer
  sur un écran d'import par lot. La lecture du code-barres de chaque
  feuille de couverture et la découpe du PDF se font **dans le
  navigateur** (PDF.js + une librairie de lecture de code-barres côté
  client) — aucune extension serveur, aucune configuration réseau requise.

L'ensemble est structuré derrière une interface `SourceNumerisation`
(capture mobile / import par lot / surveillance de dossier) : les deux
premières implémentations se codent tout de suite, la troisième devient un
simple branchement le jour où l'ONT confirme son copieur.

## Modèle de données (Lot 2)

- `documents_numerises` (`Modules\Kernel\Models\DocumentNumerise`) : table
  polymorphe (`numerisable_type`/`numerisable_id`, vers `Courrier` ou
  `Stagiaire`), placée dans **Kernel** — cross-module par nature, aucun
  des deux modules concernés ne doit dépendre de l'autre (même
  raisonnement que `DelegationResolver`, voir Lot 3 du chantier
  fonctionnel). Une nouvelle version n'écrase **jamais** la précédente :
  le papier continue de vivre après son arrivée (annotation DG, cachet
  Protocole, numéro du Secrétariat) — chaque version capture un état réel
  à une étape précise (`etape_circuit`, libellé libre plutôt qu'une FK,
  pour s'appliquer aussi bien à un courrier qu'à un stagiaire).
- `courriers.numerisation_statut` (`numerise` / `a_numeriser` /
  `non_applicable`) : le dépôt à la Réception n'est **jamais bloqué** par
  une panne de numérisation (coupure de courant, copieur en panne,
  téléphone déchargé) — `StoreCourrierRequest::numerisation_impossible`
  lève l'obligation de `piece_jointe` et bascule le statut sur
  `a_numeriser`. `GET /api/v1/courriers/a-numeriser` liste ces dossiers en
  souffrance de scan, réservé au poste Réception et à l'administrateur.
- **Décision validée avec l'utilisateur** : le tout premier scan pris à la
  Réception devient directement la version 1 d'un `DocumentNumerise`
  (aujourd'hui encore via le dépôt direct de `StoreCourrierRequest`,
  demain via la capture mobile du Lot 1) — `piece_jointe_chemin`/
  `courrier_pieces_jointes` restent en l'état (compatibilité, annexes
  multiples), mais ne sont plus le seul reflet du scan d'arrivée.

## Points à trancher avant les lots suivants

- **Format et bibliothèque de génération du code-barres** (Lot 3a) :
  `picqer/php-barcode-generator` en rendu SVG semble le candidat naturel
  (aucune extension image requise, contrairement à son rendu PNG) — à
  confirmer avant ajout au `composer.json`.
- **Bibliothèque de lecture de code-barres côté navigateur** (Lot 3a/USB) :
  à choisir au moment du Lot 3 (ex: `@zxing/browser`, `quagga2`) — dépôt
  frontend, hors périmètre de ce dépôt backend.
- **OCR** (Lot 4) : ce que ça exige précisément côté serveur reste à
  documenter avant d'écrire le code — variable d'environnement pour le
  rendre facultatif, le système devant rester utilisable sans lui.
