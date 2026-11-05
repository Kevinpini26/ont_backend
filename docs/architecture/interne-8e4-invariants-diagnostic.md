# INTERNE-8E-4.1 — Référentiel des invariants documentaires

- Version du référentiel : **1.0**, proposée pour revue le **13 octobre 2026**.
- Révision de code étudiée : `a48eb1d6919cd1625d001a6cf3a464f692d5079e`.
- Nature du livrable : documentation de conception uniquement ; aucun diagnostic exécuté.
- Catalogue : **22 invariants**. Les identifiants sont stables, ne sont pas réutilisés et restent référencés si un invariant est remplacé. Toute évolution précise la version du référentiel et du code concernés.
- Aucune migration n'est réputée exécutée sur la base métier. Les contraintes décrites sont celles **prévues dans le dépôt**, sans inspection du schéma déployé.

## A. Objet et périmètre du diagnostic

Concevoir une lecture, une comparaison, une classification et un rapport des incohérences entre documents autonomes, PDF officiels, dépôts de scans officiels, numérisations, Courriers et traces d'audit. Le diagnostic ne décide pas quelle source doit être corrigée.

Le périmètre fonctionnel de cette version est le PDF produit à la validation et le **dépôt officiel initial**, avant transmission centrale. Il comprend les références cassées, les métadonnées partielles et les fichiers sans référence connue, y compris lorsque l'origine déclarée d'un objet contredit ses relations. Une origine incohérente ne doit pas suffire à exclure cet objet du contrôle.

Les numérisations ordinaires et les documents issus d'un traitement de direction ne sont pas soumis indistinctement aux exigences autonomes. Ils restent utiles pour vérifier un rattachement ou préserver la non-régression historique.

Ce document n'autorise aucun accès à `systeme_ont`, même en lecture, ni à `ont_testing`. Aucune base ni aucun stockage documentaire réel n'a été inspecté pour l'établir. Les futurs collecteurs nécessiteront un périmètre explicitement autorisé ; leur conception ne constitue pas une autorisation d'exécution.

### Nature des règles

| Code | Nature | Interprétation |
| --- | --- | --- |
| MET | Invariant métier | État attendu du processus documentaire dans le périmètre et la version indiqués |
| APP | Garantie applicative | Contrôle ou comportement présent dans le code ; uniquement sur les chemins qui l'exécutent |
| PG | Contrainte PostgreSQL prévue | Contrainte déclarée dans une migration ; application effective non vérifiée |
| STO | Propriété du stockage | Chemin, octets ou configuration observables ; pas une garantie transactionnelle |
| DIA | Contrôle du futur diagnostic | Exigence proposée pour la comparaison et le rapport ; non implémentée |

Une règle peut combiner plusieurs natures. Sa rubrique « Portée des garanties » précise ce que chacune couvre réellement. Les gravités qualifient une violation établie, pas le degré de certitude d'une observation.

## B. Sources de vérité disponibles et limites

Les sources se corroborent ; aucune ne peut être déclarée infaillible par défaut.

| Source | Informations disponibles | Limites |
| --- | --- | --- |
| `DocumentProduitDirection` (D) | Origine, Courrier, direction, directeur, contenu versionné, validation, statut, métadonnées du PDF, pointeur du scan | Le pointeur vise `DocumentNumerise`, pas le dépôt ; les champs ne prouvent pas les octets ni l'histoire des changements |
| `DocumentProduitScanOfficiel` (S) | Document, Courrier, numérisation, version, activité, déposant, attestation, clé et empreinte de requête | Ne journalise pas toutes les tentatives ; aucune copie des octets ni disque mémorisé |
| `DocumentNumerise` (N) | Parent polymorphe, version, étape, chemin, SHA-256, taille, pages, source, qualité, auteur | Parent polymorphe non garanti par une FK vers le Courrier ; métadonnées modifiables, pas une preuve indépendante |
| `Courrier` (C) | Direction productrice, dossier, référence documentaire, statut, transitions, dispatchs et numérisations | Les scopes de visibilité peuvent masquer une ligne ; le contenu actuel ne prouve pas seul le contenu historique imprimé |
| Audits (A) | Sujet polymorphe, acteur, action, date, métadonnées | Pas de chaîne cryptographique démontrée ; rétention et exhaustivité inconnues ; précision des dates à prendre en compte |
| Stockage privé (F) | Présence, lisibilité, octets, taille, hash calculé, chemin et métadonnées du système de fichiers | Ni participation au commit PostgreSQL, ni identité métier intrinsèque ; une erreur d'accès n'est pas une absence |

Les utilisateurs et directions sont des sources auxiliaires. Le rôle actuel d'un déposant ne prouve pas son rôle à la date du dépôt. Les logs techniques peuvent aider à corréler un incident, sans devenir un registre exhaustif ou une preuve de commit.

### Références de code

Chemins relatifs à ce document, à interpréter à la révision étudiée :

- [Service du PDF officiel](../../Modules/Courrier/app/Services/DocumentProduitDirectionService.php) : `valider`, `telechargerPdfOfficiel`, promotion et compensation.
- [Service du scan officiel](../../Modules/Courrier/app/Services/DocumentProduitScanOfficielService.php) : `deposer`, `consulter`, préconditions, empreinte et promotion.
- [DocumentProduitDirection](../../Modules/Courrier/app/Models/DocumentProduitDirection.php), [DocumentProduitScanOfficiel](../../Modules/Courrier/app/Models/DocumentProduitScanOfficiel.php), [DocumentNumerise](../../Modules/Kernel/app/Models/DocumentNumerise.php), [Courrier](../../Modules/Courrier/app/Models/Courrier.php).
- [Policy documentaire](../../Modules/Courrier/app/Policies/DocumentProduitDirectionPolicy.php), [requête de dépôt](../../Modules/Courrier/app/Http/Requests/DeposerScanOfficielRequest.php), [contrôleur documentaire](../../Modules/Courrier/app/Http/Controllers/Api/DocumentProduitDirectionController.php), [route générique de numérisation](../../Modules/Courrier/app/Http/Controllers/Api/CourrierController.php).
- [Migration initiale des documents](../../Modules/Courrier/database/migrations/2026_09_28_000100_create_documents_produits_direction_table.php), [documents autonomes](../../Modules/Courrier/database/migrations/2026_10_02_000100_enable_autonomous_internal_documents.php), [métadonnées officielles](../../Modules/Courrier/database/migrations/2026_10_12_000100_add_official_document_metadata_to_documents_produits_direction.php), [dépôts officiels](../../Modules/Courrier/database/migrations/2026_10_13_000100_create_document_produit_scans_officiels_table.php), [numérisations](../../Modules/Kernel/database/migrations/2026_09_01_090000_create_documents_numerises_table.php).
- [Audit en base](../../Modules/Kernel/app/Support/DatabaseAuditLogger.php), [modèle AuditLog](../../Modules/Kernel/app/Models/AuditLog.php), [stockage configuré](../../config/filesystems.php).
- [Diagnostic PDF existant](../../Modules/Courrier/app/Console/ReconcilerPdfOfficielDocumentAutonomeCommand.php).

## C. Invariants du PDF officiel

### PDF-01 — Identité documentaire et référence

- **Description :** le PDF officiel appartient à un document autonome et à son Courrier producteur.
- **Objets concernés :** D, C, direction, dossier, A.
- **Condition attendue :** `origine = autonome`, source et traitement nuls, Courrier existant, direction productrice concordante, dossier présent et référence documentaire attribuée lors de la validation.
- **Portée des garanties :** MET ; APP vérifie ces relations à la validation et exige alors une référence initialement nulle avant attribution ; PG prévoit l'origine cohérente et l'unicité de `D.courrier_id` ; DIA recoupe relations, référence et audit.
- **Exceptions historiques :** H1 et H2 de la section F ; une référence historique ne doit pas être réattribuée pour satisfaire cette règle.
- **Gravité en cas de violation :** critique pour mauvais rattachement ; majeure pour référence manquante.
- **Preuves nécessaires :** D et C complets, dossier/direction référencés, audit de validation et, si disponible, attribution de référence.
- **Limites de conclusion :** cohérence des IDs et de la référence ne prouve pas la fidélité du texte imprimé ; ne pas inventer un format de référence absent de cette règle.

### PDF-02 — Version du contenu figée

- **Description :** le PDF correspond à la version de contenu validée.
- **Objets concernés :** D, A, F.
- **Condition attendue :** `version_contenu > 0`, `pdf_officiel_version_contenu = version_contenu`, date de génération présente après validation ; concordance avec la version portée par l'audit lorsqu'il est disponible.
- **Portée des garanties :** MET ; APP renseigne les métadonnées et compare les versions au téléchargement et au dépôt ; PG prévoit une version de contenu positive pour l'autonome, pas l'égalité des deux colonnes ; DIA compare les valeurs.
- **Exceptions historiques :** H1/H2 ; ne pas fabriquer une version pour une ligne ancienne incomplète.
- **Gravité en cas de violation :** majeure.
- **Preuves nécessaires :** versions, dates et audit de validation, chemin et hash du fichier.
- **Limites de conclusion :** une égalité de versions ne démontre pas que le contenu n'a jamais été modifié directement ; aucune régénération comparative autorisée.

### PDF-03 — Chemin officiel privé

- **Description :** le PDF final respecte son espace de stockage documentaire.
- **Objets concernés :** D, F, configuration du disque.
- **Condition attendue :** disque `local`, racine configurée `storage/app/private`, chemin produit `documents-internes/officiels/{D.id}/{version_contenu}-{sha256}.pdf` ; le staging `documents-internes/officiels/.staging/{uuid}.pdf` ne constitue pas un PDF final référencé.
- **Portée des garanties :** APP génère ce nom mais la lecture ne contrôle que le préfixe du document et l'absence de `..` ; STO décrit la configuration du dépôt ; DIA compare le nom complet et vérifie le confinement avant lecture. Aucune contrainte PG de chemin.
- **Exceptions historiques :** H2/H3 ; un nom ancien différent exige une provenance documentée, pas une normalisation silencieuse.
- **Gravité en cas de violation :** majeure ; critique si sortie de racine, mauvais document ou exposition publique établie.
- **Preuves nécessaires :** chemin brut, version, hash, configuration résolue autorisée et confinement du chemin.
- **Limites de conclusion :** un préfixe conforme ne prouve ni permissions privées ni absence de lien symbolique ; le nom du disque n'est pas persisté dans D.

### PDF-04 — Présence et intégrité des octets

- **Description :** le fichier référencé est lisible et concorde avec son empreinte enregistrée.
- **Objets concernés :** D, F, A.
- **Condition attendue :** SHA-256 hexadécimal minuscule de 64 caractères, hash des octets identique ; fichier PDF cohérent avec les marqueurs produits `%PDF-` et `%%EOF`.
- **Portée des garanties :** APP contrôle hash et marqueurs à la génération ; le téléchargement et la précondition de dépôt vérifient hash et début `%PDF-`, sans contrôle systématique de fin à ces étapes ; DIA distingue présence, lecture, hash et format. STO fournit les octets, sans garantie PG.
- **Exceptions historiques :** H1/H2 ; un hash absent empêche la vérification cryptographique, sans justifier son recalcul comme nouvelle vérité.
- **Gravité en cas de violation :** critique pour hash divergent ; majeure pour absence, illisibilité ou format invalide.
- **Preuves nécessaires :** lecture stable, hash calculé, hash enregistré et éventuelle copie du hash dans l'audit.
- **Limites de conclusion :** aucune taille officielle persistée dans D ; on ne peut donc pas prouver une divergence de taille du PDF original sans référence indépendante. Des marqueurs PDF ne garantissent pas sa lisibilité complète.

### PDF-05 — Persistance après validation et après dépôt

- **Description :** le dépôt du scan conserve le PDF officiel original.
- **Objets concernés :** D, S, C, F.
- **Condition attendue :** au statut `valide`, métadonnées et fichier officiels présents ; au statut `scan_enregistre`, ces éléments restent présents et la chaîne du scan satisfait la section D.
- **Portée des garanties :** MET ; APP écrit le PDF à la validation et ne le remplace pas au dépôt ; DIA contrôle les deux statuts. La policy du PDF autorise ces deux statuts, mais `telechargerPdfOfficiel()` n'accepte actuellement que `valide` : écart applicatif à instruire, pas preuve de disparition du PDF.
- **Exceptions historiques :** H1/H2 ; ne pas exiger ces métadonnées de tous les anciens documents validés sans établir leur régime.
- **Gravité en cas de violation :** majeure ; critique si le statut affirme un scan enregistré sans chaîne valide.
- **Preuves nécessaires :** statut, métadonnées PDF, inventaire stable et chaîne de dépôt.
- **Limites de conclusion :** un refus HTTP n'identifie pas à lui seul une corruption ; un instantané ne prouve pas l'immuabilité passée.

### PDF-06 — Absence de régénération silencieuse

- **Description :** consultation et diagnostic ne remplacent pas une preuve documentaire absente ou altérée.
- **Objets concernés :** D, F, A, services de lecture, futur diagnostic.
- **Condition attendue :** lecture, refus ou signalement explicite ; aucun appel de génération, validation, réattribution de référence ou compensation depuis le diagnostic.
- **Portée des garanties :** MET ; APP le téléchargement lit le fichier existant, sans régénération ; DIA impose cette séparation à tous ses lecteurs. Pas de garantie d'immuabilité physique du stockage.
- **Exceptions historiques :** aucune autorisation de régénération implicite, même pour H1/H2.
- **Gravité en cas de violation :** critique.
- **Preuves nécessaires :** inspection des chemins d'appel ; futurs tests avec générateur interdit et comparaison avant/après des artefacts synthétiques.
- **Limites de conclusion :** le présent audit statique ne prouve pas l'absence d'opérations externes passées.

## D. Invariants du scan officiel

### SCN-01 — Document autonome validé et directeur responsable

- **Description :** le dépôt initial repose sur une validation documentaire attribuée.
- **Objets concernés :** D, C, directeur, A, PDF officiel.
- **Condition attendue :** avant dépôt initial, `statut = valide`, origine autonome, source/traitement nuls, directeur renseigné, `valide_par_id = directeur_id`, date de validation présente et PDF officiel cohérent ; après dépôt, statut `scan_enregistre` avec ces preuves de validation conservées.
- **Portée des garanties :** MET ; APP vérifie ces préconditions pour le premier dépôt ; PG prévoit l'origine cohérente et la FK du directeur, pas toute la validation ; DIA recoupe ces éléments même si la consultation ne les reprend pas tous.
- **Exceptions historiques :** H1/H2 ; aucune assimilation automatique d'une ancienne numérisation à un dépôt attesté.
- **Gravité en cas de violation :** critique.
- **Preuves nécessaires :** validation, directeur désigné, relations documentaires et contrôles PDF-01 à PDF-05.
- **Limites de conclusion :** le rejeu avec une clé existante passe avant les préconditions du dépôt initial ; son succès ne confirme pas cet invariant.

### SCN-02 — Déposant habilité au moment du dépôt

- **Description :** le secrétariat producteur porte la responsabilité du dépôt.
- **Objets concernés :** D, S, N, utilisateur, A.
- **Condition attendue :** acteur `SECRETARIAT_DIRECTION` de la direction productrice lors du dépôt, utilisateur existant, `S.depose_par_id = N.capture_par_id`, acteur d'audit concordant.
- **Portée des garanties :** MET ; APP recharge l'utilisateur dans la transaction après verrouillage du document et vérifie rôle/direction ; PG prévoit une FK du déposant avec suppression restreinte, pas son habilitation ; DIA distingue identité persistée et preuve d'habilitation historique.
- **Exceptions historiques :** H2 ; changement ultérieur de rôle ou de direction autorisé à expliquer, sans conclure rétrospectivement à un dépôt illicite.
- **Gravité en cas de violation :** critique pour contradiction d'identité ou absence d'habilitation historiquement prouvée.
- **Preuves nécessaires :** IDs, audit, et historique fiable des affectations pour une conclusion sur l'autorisation passée.
- **Limites de conclusion :** l'état actuel de l'utilisateur ne suffit pas ; le contrôle applicatif n'est pas une contrainte PostgreSQL sur les rôles ni un verrou explicite de la ligne utilisateur.

### SCN-03 — Attestation nominative cohérente

- **Description :** le dépôt conserve la déclaration explicite de vérification physique.
- **Objets concernés :** S, N, utilisateur, A.
- **Condition attendue :** confirmation vraie, version d'attestation 1 pour ce référentiel, date présente, identité du déposant cohérente ; empreinte conforme à IDE-01 et métadonnées d'audit concordantes si disponibles.
- **Portée des garanties :** MET ; APP la requête HTTP exige l'acceptation et le service enregistre la version constante ; PG prévoit confirmation vraie, version positive et date non nulle, pas spécifiquement version 1 ; DIA compare les sources.
- **Exceptions historiques :** H2/H3 ; une numérisation historique non attestée n'acquiert pas une attestation par classement ou rétrodatation.
- **Gravité en cas de violation :** critique.
- **Preuves nécessaires :** champs du dépôt, auteur de capture, définition versionnée du texte et audit disponible.
- **Limites de conclusion :** le texte est une constante applicative, pas une copie enregistrée par dépôt ; sa présence ne prouve ni signature manuscrite ni cachet. Le service interne ne reçoit pas un booléen d'acceptation : l'exigence d'acceptation explicite appartient au chemin HTTP.

### SCN-04 — Pointeur officiel concordant

- **Description :** le document désigne exactement la numérisation de son dépôt actif.
- **Objets concernés :** D, S, N.
- **Condition attendue :** pour un dépôt enregistré, `D.scan_officiel_document_id = S.document_numerise_id`, N existe et S appartient à D ; ce pointeur n'est pas l'ID de S.
- **Portée des garanties :** MET ; APP affecte le pointeur dans la transaction et le contrôle à la consultation ; PG prévoit une FK vers N sans garantir l'égalité avec S ; DIA compare toutes les relations sans choisir un candidat.
- **Exceptions historiques :** absence normale avant dépôt ; H1/H2 ne permettent pas de remplir automatiquement le pointeur.
- **Gravité en cas de violation :** critique.
- **Preuves nécessaires :** lignes D/S/N complètes et comptage des dépôts actifs.
- **Limites de conclusion :** la seule existence de N ne prouve pas qu'elle appartient au bon Courrier ou que le fichier est valide.

### SCN-05 — Un seul dépôt initial, actif

- **Description :** le régime courant ne prévoit aucun remplacement ni dépôt officiel supplémentaire.
- **Objets concernés :** D, ensemble des S, N.
- **Condition attendue :** après succès initial, exactement un dépôt total, actif, de version 1 ; aucune numérisation partagée entre deux dépôts.
- **Portée des garanties :** MET ; APP refuse un nouveau dépôt dès qu'un S existe et la consultation exige un seul S total et actif ; PG prévoit unicité de N, du couple document/version et un index partiel d'unicité de l'actif, mais autorise structurellement plusieurs versions inactives ; DIA compte tous les dépôts.
- **Exceptions historiques :** zéro dépôt est normal avant dépôt ; H3 impose un autre référentiel pour un futur remplacement autorisé.
- **Gravité en cas de violation :** critique pour plusieurs actifs ou partage de N ; majeure pour autre écart au régime initial.
- **Preuves nécessaires :** inventaire complet des dépôts, y compris inactifs ; preuve du schéma effectif uniquement si disponible dans un périmètre autorisé.
- **Limites de conclusion :** plusieurs actifs sont incompatibles avec l'index prévu effectivement appliqué ; ne pas en déduire sans preuve pourquoi cet index n'a pas protégé les données.

### SCN-06 — Association complète au Courrier producteur

- **Description :** toutes les branches de la chaîne désignent le même parent métier.
- **Objets concernés :** D, S, N, C, direction.
- **Condition attendue :** `D.courrier_id = S.courrier_id = C.id`, `S.document_produit_direction_id = D.id`, type polymorphe de N égal au morph class de C, `N.numerisable_id = C.id` et direction productrice concordante.
- **Portée des garanties :** MET ; APP contrôle l'association au dépôt et à la lecture ; PG prévoit une FK composite de S vers le couple D/C, sans garantir le parent polymorphe ni la direction ; DIA vérifie toutes les égalités.
- **Exceptions historiques :** H1/H2 ; les alias polymorphes doivent être interprétés selon leur version, pas remplacés par un nom de classe supposé.
- **Gravité en cas de violation :** critique.
- **Preuves nécessaires :** objets liés, règles de morphisme et directions.
- **Limites de conclusion :** des contenus ou hashes identiques ne prouvent pas que deux documents ont le même parent ; les scopes de visibilité ne doivent pas être confondus avec une absence physique.

### SCN-07 — Intégrité et métadonnées du scan

- **Description :** le scan référencé concorde avec les métadonnées du dépôt.
- **Objets concernés :** S, N, F, A.
- **Condition attendue :** hash N minuscule sur 64 caractères et égal au hash des octets ; taille égale à `poids_octets > 0`, pages positives, source `televersement`, qualité `bonne`, PDF débutant par `%PDF-` et contenant `%%EOF`.
- **Portée des garanties :** MET ; APP parse le PDF et vérifie la qualité au dépôt, puis contrôle métadonnées, taille/hash et marqueurs à la consultation ; DIA compare taille et hash sur les mêmes octets. La consultation ne recompte pas les pages et ne reparse pas intégralement le PDF.
- **Exceptions historiques :** H1/H2 pour les numérisations ordinaires ; pas d'exception implicite pour un dépôt officiel du régime courant.
- **Gravité en cas de violation :** critique pour hash divergent ; majeure pour taille, disponibilité ou autres métadonnées invalides.
- **Preuves nécessaires :** lecture stable, métadonnées N et audit disponible ; résultat de parseur seulement si un contrôle futur explicitement prévu le produit.
- **Limites de conclusion :** qualité enregistrée et seuil de poids ne démontrent pas lisibilité humaine, conformité physique ou absence de contenu PDF dangereux.

### SCN-08 — Emplacement privé du scan

- **Description :** le scan final est isolé dans le répertoire de son document.
- **Objets concernés :** D, N, F, configuration.
- **Condition attendue :** disque `local`, sous `storage/app/private`, chemin `documents-internes/scans-officiels/{D.id}/{uuid}.pdf` ; aucun pointeur final vers `.staging`.
- **Portée des garanties :** APP produit ce chemin et la consultation en contrôle la forme par expression régulière, insensible à la casse ; STO décrit la racine configurée ; DIA vérifie confinement et chemin avant ouverture. Pas de colonne disque ni de contrainte PG correspondante.
- **Exceptions historiques :** H2/H3 ; un emplacement différent exige une provenance, sans exploration automatique d'autres disques.
- **Gravité en cas de violation :** majeure ; critique pour mauvais document, sortie de racine ou exposition établie.
- **Preuves nécessaires :** chemin brut, ID documentaire, configuration autorisée et résolution confinée.
- **Limites de conclusion :** un disque inattendu ne se déduit pas de la seule absence sur `local` ; les liens symboliques et permissions demandent des preuves distinctes.

### SCN-09 — Aucune transmission centrale prématurée

- **Description :** le dépôt initial reste dans le circuit autonome privé.
- **Objets concernés :** D, C, N, transitions et dispatchs.
- **Condition attendue :** C à `brouillon_direction`, sans numéro d'enregistrement, transition ni dispatch ; champs de transmission/réception de D nuls ; étape de N égale à ce statut. La référence documentaire, elle, doit déjà exister.
- **Portée des garanties :** MET ; APP vérifie ces conditions au premier dépôt et les conditions de circuit à la consultation ; DIA compare les inventaires. Les contraintes PG de scans ne garantissent pas ces états.
- **Exceptions historiques :** H1 pour les circuits historiques ; H3 pour une évolution future de transmission explicitement documentée.
- **Gravité en cas de violation :** critique dans le périmètre du dépôt initial.
- **Preuves nécessaires :** champs D/C et inventaire complet des transitions/dispatchs.
- **Limites de conclusion :** une relation masquée ou un export incomplet ne prouve pas l'absence d'événement central ; ne pas appliquer cette règle à toute numérisation historique.

### SCN-10 — Consultation autorisée par les deux routes

- **Description :** aucune route de téléchargement ne doit contourner les contrôles du scan officiel.
- **Objets concernés :** utilisateur, D, C, S, N, F, policies et contrôleurs.
- **Condition attendue :** accès au secrétariat producteur ou au directeur/responsable personnellement assigné de la même direction, avec trace de soumission pour ces derniers ; scan enregistré et chaîne valide ; flux privé, sans exposition du chemin interne.
- **Portée des garanties :** MET ; APP la route dédiée vérifie la policy puis `consulter()` ; la route générique vérifie d'abord `view` sur C, le parent de N et des relations supplémentaires avant de rejoindre `consulter()` ; DIA exige une matrice future des deux routes. La parité totale n'est pas une garantie démontrée.
- **Exceptions historiques :** H1 : les numérisations ordinaires conservent leurs règles propres ; aucune nouvelle permission implicite pour administrateur ou acteur central.
- **Gravité en cas de violation :** critique pour divulgation ; majeure pour refus injustifié.
- **Preuves nécessaires :** rôle/affectation courants, assignation, soumission, chaîne et futurs résultats HTTP réels ; en-têtes `private, no-store` et `nosniff` du scan.
- **Limites de conclusion :** la route générique a une autorisation supplémentaire ; ne pas promettre le même code de refus partout. Un test avec service simulé ne prouve pas l'intégrité du flux réel.

## E. Trois versions différentes

### VER-01 — Indépendance des compteurs

- **Description :** trois versions décrivent trois histoires différentes.
- **Objets concernés :** D, S, N, ensemble des numérisations de C.
- **Condition attendue :** `D.version_contenu` versionne le contenu édité ; `D.pdf_officiel_version_contenu` en désigne la version figée ; `S.version = 1` désigne le dépôt officiel initial ; `N.version` suit les numérisations du parent polymorphe et vaut `max + 1` au dépôt.
- **Portée des garanties :** MET ; APP calcule ces valeurs et verrouille C pour allouer la version N ; PG prévoit unicité document/version S et positivité S, mais la migration N lue ne prévoit qu'un index non unique sur parent/type/version ; DIA recherche valeurs invalides et doublons sans confondre les compteurs.
- **Exceptions historiques :** H1/H2 ; un trou dans l'historique N ne prouve pas à lui seul une suppression fautive ; H3 pour un futur remplacement officiel.
- **Gravité en cas de violation :** majeure.
- **Preuves nécessaires :** trois versions, historique complet disponible de C et régime applicable.
- **Limites de conclusion :** il est normal que `S.version != N.version` et que les deux diffèrent de `D.version_contenu`. Le maximum actuel ne permet pas de recalculer avec certitude le maximum au moment du dépôt.

## F. États normaux, transitoires, incohérents et historiques

| État | Lecture attendue |
| --- | --- |
| Brouillon, soumis ou à corriger, sans dépôt | Absence de PDF officiel ou de scan normalement possible avant validation ; ne pas appliquer une obligation post-validation |
| `valide`, PDF cohérent, aucun dépôt/pointeur | État normal en attente du scan |
| `scan_enregistre`, PDF conservé, un S actif attesté, N et fichier cohérents | État normal après dépôt initial, avant entrée centrale |
| Staging visible ou fichier final sans ligne pendant une opération | Situation transitoire possible ; suspicion à stabiliser, pas orphelin prouvé |
| `scan_enregistre` sans chaîne valide | Incohérence si les preuves suffisantes sont disponibles ; sinon indisponibilité ou incertitude à qualifier |
| `valide` avec S actif ou pointeur sans dépôt concordant | Incohérence du régime courant après observation cohérente ; ne pas confondre avec `valide` sans dépôt |
| Plusieurs actifs ou mauvaise association établie | Incohérence critique ; aucun choix automatique du dépôt « le plus récent » |

Exceptions historiques référencées dans les invariants :

- **H1 — Autre circuit établi :** document d'origine traitement de direction ou numérisation ordinaire, sans indice contradictoire de dépôt autonome. Les obligations autonomes inapplicables sont « hors périmètre historique ».
- **H2 — Historique incomplet :** anciennes données, audits manquants, versions ou provenance inconnues. Ce n'est pas une dispense automatique : qualifier « indéterminé » ou « non vérifiable » selon la preuve manquante, sans inventer une date de déploiement.
- **H3 — Autre version du processus :** remplacement, nouvelle attestation, migration de stockage ou transmission autorisée par une évolution documentée. Exiger le référentiel correspondant ; ne pas déclarer une conformité à la version 1.0 par supposition.

Une exception doit citer sa preuve d'applicabilité. Aucun classement historique ne justifie un mauvais rattachement avéré, une divulgation ou une réécriture d'audit.

## G. Classification des résultats

| Classe | Critère |
| --- | --- |
| Conforme | Tous les contrôles applicables et annoncés ont des preuves suffisantes et concordantes, pour l'instant et le périmètre observés |
| Incohérence confirmée | Au moins une condition applicable est contredite par une preuve suffisamment complète et stable |
| Anomalie suspectée | Indice réel, mais opération en cours, inventaire partiel ou autre explication transitoire non éliminée |
| Indéterminé | Plusieurs explications restent compatibles avec les observations ; impossible de trancher l'histoire ou l'issue du commit |
| Non vérifiable | Une preuve nécessaire est indisponible, inaccessible ou hors de la capacité du contrôle, par exemple octets illisibles ou attestation physique non vérifiable cryptographiquement |
| Hors périmètre historique | Provenance établie d'un autre régime auquel l'invariant autonome ne s'applique pas |

La classe est portée par contrôle, pas seulement par document. Un document peut avoir un hash conforme et une attestation non vérifiable. Le rapport global doit conserver les réserves et ne jamais convertir un contrôle omis en succès.

## H. Gravité et critères de preuve

| Gravité | Critère |
| --- | --- |
| Critique | Confidentialité, identité documentaire, intégrité par hash, unicité officielle, attestation ou affirmation de dépôt compromise |
| Majeure | Disponibilité, version, métadonnées ou traçabilité déficiente, sans preuve suffisante d'une atteinte critique |
| Modérée | Résidu ou écart opérationnel sans effet métier établi, notamment candidat temporaire abandonné |

Une suspicion critique reste une suspicion : gravité et certitude sont deux dimensions indépendantes. Chaque résultat conserve règle/version, IDs, attendu/observé, source, instant d'observation, complétude, erreurs, références de preuves et limites. Aucune accusation de fraude ne découle automatiquement d'une divergence.

### TRC-01 — Audit concordant avec l'opération

- **Description :** validation et dépôt disposent d'une trace compatible avec leur état persistant.
- **Objets concernés :** D, S, N, C, A, utilisateur.
- **Condition attendue :** audit `document_direction.valide` avec référence/version/hash PDF pour l'autonome ; audit `document_direction.scan_officiel_depose` avec document, Courrier, déposant, date d'attestation, version 1, hash N et version d'attestation concordants ; sujet polymorphe et acteur cohérents.
- **Portée des garanties :** MET ; APP appelle `DatabaseAuditLogger` dans la transaction et son modèle ne déclare pas de connexion distincte : atomicité attendue sur la connexion commune, pas preuve de configuration déployée ; DIA compare actions et payloads. Pas de contrainte PG liant chaque audit à l'opération ni d'unicité de l'action démontrée.
- **Exceptions historiques :** H1 conserve le payload historique non autonome ; H2 tient compte de la rétention et de l'exhaustivité inconnues.
- **Gravité en cas de violation :** majeure pour absence ; critique pour contradiction d'identité ou de hash établie.
- **Preuves nécessaires :** audit complet, type de sujet, acteur, payload et précision/fuseau des dates ; provenance du corpus d'audit.
- **Limites de conclusion :** l'audit de dépôt ne contient ni clé d'idempotence, ni ID de numérisation, ni chemin ; absence d'audit ne prouve pas absence d'opération. Ne pas exiger égalité textuelle des dates si leurs précisions diffèrent.

### IDE-01 — Clé et empreinte d'idempotence cohérentes

- **Description :** la clé identifie une requête de dépôt pour un document donné.
- **Objets concernés :** D, S, N, déposant, éventuelles traces de tentative.
- **Condition attendue :** UUID validé sur le chemin HTTP et normalisé en minuscules ; unicité du couple document/clé ; empreinte égale au SHA-256 du JSON ordonné contenant `sha256`, `depose_par_id` entier, `attestation_confirmee: true`, `attestation_version: 1`, avec les options `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES` utilisées par le service.
- **Portée des garanties :** MET ; APP même clé/même empreinte renvoie S, autre empreinte donne conflit ; PG impose clé non vide, unicité par document et format hexadécimal de l'empreinte, pas UUID ni normalisation ; DIA recalcule l'empreinte à partir des métadonnées et vérifie séparément les octets.
- **Exceptions historiques :** H2 ; même clé sur deux documents différents n'est pas un conflit ; H3 pour une autre version attestée du protocole.
- **Gravité en cas de violation :** majeure ; une divergence du hash des octets reste critique selon SCN-07.
- **Preuves nécessaires :** S/N, encodage exact et identité du déposant ; au moins deux traces fiables pour prouver une réutilisation historique avec autre empreinte.
- **Limites de conclusion :** une empreinte actuelle incohérente ne prouve pas une tentative passée ; les requêtes rejetées ne sont pas toutes historisées. Le rejeu ne revérifie pas tout le fichier/pointeur et peut créer un dépôt si la première transaction n'a pas persisté : ce n'est jamais une lecture diagnostique.

### STO-01 — Références et inventaire bidirectionnels

- **Description :** distinguer fichiers référencés, fichiers sans référence connue et références sans fichier accessible.
- **Objets concernés :** D, S, N, F des deux espaces officiels et de leurs staging.
- **Condition attendue :** fichier final référencé lisible pour chaque chaîne réussie ; inventaire inverse des chemins, numérisations isolées et fichiers non référencés ; staging jamais qualifié automatiquement de déchet.
- **Portée des garanties :** STO ne garantit pas l'atomicité ; APP peut laisser des résidus ; DIA est le contrôle proposé, sans contrainte PG sur la présence physique.
- **Exceptions historiques :** H1/H2/H3 ; inventaire d'autres consommateurs autorisés à prendre en compte avant toute conclusion d'absence de référence.
- **Gravité en cas de violation :** modérée pour temporaire suspect ; majeure pour final sans référence connue ou référence sans fichier.
- **Preuves nécessaires :** inventaire complet et borné, références de D/N/S, dates et tailles/hash, observations répétées avec provenance et stabilité documentées.
- **Limites de conclusion :** ancienneté ou absence de ligne à un instant ne prouve ni abandon ni « jamais persisté » ; un fichier peut être en promotion/commit. Ne pas chercher arbitrairement un doublon sur d'autres disques.

### OBS-01 — Conclusion proportionnée aux preuves

- **Description :** le rapport reflète explicitement ce qui a pu être observé.
- **Objets concernés :** toutes les sources, collecteurs et rapport futur.
- **Condition attendue :** preuves datées, périmètre/version connus, stabilité qualifiée, erreurs et sources manquantes conservées ; classe choisie selon G, indépendamment de la gravité.
- **Portée des garanties :** DIA uniquement ; aucune garantie actuelle d'instantané commun base/fichiers. La lecture future doit supporter valeurs brutes invalides et erreurs de casts sans perdre les autres constats.
- **Exceptions historiques :** H2/H3 renforcent les réserves ; aucune exception autorisant à annoncer une conformité sans preuve.
- **Gravité en cas de violation :** majeure ; critique si une fausse conclusion autorise une divulgation ou une correction destructive.
- **Preuves nécessaires :** manifeste des sources, début/fin d'observation, erreurs, règles appliquées/non appliquées et critères de stabilisation.
- **Limites de conclusion :** deux lectures identiques ne constituent pas une preuve absolue d'absence de transaction active ; elles réduisent seulement certaines incertitudes.

## I. Matrice des anomalies détectables

« Réparation » désigne une possibilité théorique à instruire dans un chantier séparé, jamais une action du diagnostic. **Validation humaine obligatoire pour toute décision de réparation et pour toute ligne de cette matrice.**

| Anomalie / règles | Gravité | Preuve et détectabilité | Faux positif ou limite | Réparation envisageable, hors diagnostic |
| --- | --- | --- | --- | --- |
| Temporaire abandonné — STO-01 | Modérée | Staging stable et ancien : candidat détectable, abandon à corroborer | Opération lente ou suspendue ; âge seul insuffisant | Qualification humaine ; aucune suppression prévue |
| Fichier promu sans ligne — STO-01 | Majeure | Fichier final sans référence dans inventaire complet et stabilisé | Commit en cours, export incomplet, référence hors périmètre | Rattachement seulement avec provenance indépendante suffisante |
| Ligne sans fichier — PDF-04, SCN-07 | Majeure | Absence confirmée sur le stockage attendu accessible | Montage ou permission défaillante = non vérifiable | Copie authentifiée éventuellement restaurable |
| SHA-256 divergent — PDF-04, SCN-07 | Critique | Hash des mêmes octets stables différent du hash persistant | Mutation pendant lecture ; source correcte non déterminée | Restauration sous preuve ; pas de remplacement opportuniste du hash |
| Taille divergente — SCN-07 | Majeure | Taille réelle différente de N.poids_octets | Pas de taille de référence D pour le PDF original | Arbitrage sur fichier ou métadonnée, avec preuve |
| Pointeur absent/incorrect — SCN-04 | Critique | Pointeur différent de la N du S actif, ou absent après dépôt | Absence normale avant dépôt | Candidat possible seulement si chaîne unique et prouvée |
| Plusieurs actifs — SCN-05 | Critique | Comptage complet des S actifs par D | Données fusionnées ; incompatible avec index prévu appliqué | Arbitrage métier ; aucun choix automatique |
| Version incohérente — PDF-02, VER-01 | Majeure | Valeur invalide, doublon N, S autre que 1 dans ce régime ou versions PDF/contenu divergentes | Compteurs distincts normalement différents ; trous historiques | Reconstruction uniquement avec histoire fiable |
| Mauvais document/Courrier — PDF-01, SCN-06 | Critique | IDs, morphisme ou direction contradictoires | Alias mal interprété, scopes masquant une relation | Aucun rattachement par hash ou proximité seule |
| Attestation absente/incohérente — SCN-03 | Critique | Confirmation/version/date/identité/empreinte contradictoires | H2/H3 ; présence physique de signature non démontrable | Pas de reconstitution rétroactive d'une attestation physique |
| Clé réutilisée avec autre empreinte — IDE-01 | Majeure | Tentatives distinctes fiables ; à défaut seule incohérence actuelle détectable | Tentative rejetée absente de l'historique ; clé interdocuments permise | Investigation, sans réécriture du passé |
| `scan_enregistre` sans scan valide — SCN-01/04/05/07 | Critique | Statut contredit par chaîne ou octets contrôlables | Indisponibilité du stockage ≠ corruption démontrée | Décision métier après examen de toutes les preuves |
| Actif avec document `valide` — PDF-05, SCN-04/05 | Critique | État persistant stable incompatible avec fin de dépôt | `valide` sans S est normal ; capture désynchronisée | Aucun changement automatique de statut |
| Disque/chemin inattendu — PDF-03, SCN-08 | Majeure à critique | Configuration et chemin incompatibles ; exposition à prouver | Disque non mémorisé ; absence locale ne localise pas ailleurs | Relocalisation éventuelle dans une opération distincte |
| Audit manquant/contradictoire — TRC-01 | Majeure à critique | Corpus complet disponible ou contradiction explicite acteur/hash | Rétention, précision temporelle, export partiel | Audit historique non réécrit ; constat séparé |
| Transmission prématurée — SCN-09 | Critique | Événement central ou champs renseignés dans ce régime | Autre version du processus documentée | Arbitrage métier séparé |

## J. Limites de conclusion

### PostgreSQL, fichiers et incertitude de commit

Le dépôt verrouille D puis C dans une transaction à une tentative. La promotion précède la création de N/S, l'affectation du pointeur, le statut et l'audit. Le stockage ne participe pas au commit PostgreSQL.

Le drapeau du scan `promotionCommencee` est positionné **avant** `move()`. Toute exception ultérieure conserve les fichiers et produit une réponse 503 d'incertitude, y compris un déplacement refusé ou un rollback effectivement réalisé. Cette réponse ne prouve ni succès ni échec de commit. Un temporaire peut aussi subsister avant promotion.

Le PDF original suit un autre mécanisme : compensation des chemins avant `transactionPretACommit`, conservation ensuite. Ce drapeau n'est pas une preuve de commit. Comme les chemins sont affectés avant vérification de leur disponibilité, la compensation peut également viser un chemin préexistant lors d'une collision. Le diagnostic ne doit pas réutiliser ce mécanisme ; son examen appartient à 8E-4.7.

| Observation stabilisée | Conclusion maximale |
| --- | --- |
| S/N/D/A et fichier concordants | État actuel compatible avec un dépôt réussi ; pas reconstitution certaine de toute tentative |
| Fichier final présent, lignes absentes | Potentiellement orphelin ; cause et absence historique de persistance non prouvées |
| Chaîne partielle ou contradictoire | Incohérence constatée si sources complètes ; cause à investiguer |
| Sources inaccessibles ou instables | Non vérifiable, suspecté ou indéterminé selon le contrôle |

### Authenticité, idempotence et historique

Le SHA-256 établit une égalité d'octets avec une valeur de référence. Si octets, métadonnées et audit sont modifiés ensemble, les seules comparaisons internes peuvent rester concordantes. Aucune signature cryptographique du papier ou chaîne d'audit inviolable n'est démontrée par le code étudié.

Le scan signé et le PDF original sont deux objets différents : leurs hashes n'ont pas à être égaux. Une inspection humaine du document physique ou de sa représentation est distincte de l'intégrité binaire.

Sans journal fiable de toutes les requêtes, il est impossible de prouver rétrospectivement chaque conflit d'idempotence. Après rollback, une requête identique peut créer un nouveau fichier et un dépôt, tout en laissant un ancien fichier promu ; le diagnostic ne doit pas rejouer la requête.

Le code des migrations décrit un schéma prévu, pas un inventaire de contraintes actives. Les données historiques incomplètes imposent une conclusion limitée, sans rétroattribuer une attestation, un hash ou une date.

### Portée du diagnostic existant

`courrier:reconcilier-pdf-officiels` n'a pas été exécutée. Son code signale les fichiers sans référence sous le préfixe des PDF, conserve les fichiers, et vérifie présence/hash seulement pour les documents autonomes `valide` avec chemin non nul. Il ne couvre pas les scans, les PDF après passage à `scan_enregistre`, les chemins nuls, la stabilisation, ni toute la chaîne relationnelle. Il écrit des logs ; ce n'est pas un lecteur pur réutilisable sans examen.

## K. Non-destruction et validation humaine

### NDS-01 — Diagnostic strictement non réparateur

- **Description :** l'observation ne transforme jamais les données qu'elle évalue.
- **Objets concernés :** D, S, N, C, A, F, collecteurs et rapports.
- **Condition attendue :** lectures, comparaisons, classification et rapport seulement ; aucune suppression, promotion, déplacement, régénération, mutation de pointeur/statut/attestation/clé, réécriture d'audit ou déclenchement d'un service métier. Le rapport est un artefact séparé, pas un audit métier rétroactif.
- **Portée des garanties :** DIA et règle de gouvernance ; aucune implémentation de ce futur diagnostic n'est revendiquée. Les compensations du code existant ne sont pas autorisées par cette règle.
- **Exceptions historiques :** aucune ; toute réparation future relève d'une spécification et d'une autorisation distinctes.
- **Gravité en cas de violation :** critique.
- **Preuves nécessaires :** revue des dépendances, droits minimaux, futur contrôle d'absence de mutation sur fixtures et environnement jetable ; périmètre des sorties explicite.
- **Limites de conclusion :** lecture du système de fichiers susceptible d'affecter des métadonnées d'accès selon le support ; ne pas promettre une acquisition forensique bit à bit sans dispositif dédié. Une validation humaine n'autorise pas implicitement l'exécution d'une réparation.

Procédure humaine proposée : constituer les preuves, distinguer faits et hypothèses, faire examiner le rattachement et l'intégrité par les responsables compétents, puis enregistrer une décision dans un rapport séparé : conforme pour les contrôles réalisés, observation à renouveler, investigation nécessaire ou réparation à concevoir.

Les rapports doivent rester privés et minimiser les données exposées : pas de contenu PDF, d'identifiants de connexion ou de chemins sensibles dans une sortie publique. Les erreurs de lecture restent visibles sous une forme appropriée au lecteur autorisé.

## L. Critères d'acceptation des futurs lots 8E-4.2 à 8E-4.7

Ces critères cadrent les travaux futurs ; ils n'autorisent ni implémentation ni exécution dans le présent lot.

| Lot | Livrable attendu | Critères d'acceptation |
| --- | --- | --- |
| 8E-4.2 | Contrat d'inventaire et de rapport | Version du code/référentiel, provenance, périmètre, horodatages, complétude, IDs et valeurs brutes ; résultat par invariant avec classe/gravité/preuves/limites ; erreurs partielles et règles non applicables explicites ; aucune donnée sensible superflue |
| 8E-4.3 | Comparateur pur sur fixtures synthétiques | Chaque invariant et anomalie de ce référentiel est relié à une fixture ou à une limite explicitement non calculable ; six classes représentées ; distinction des trois versions et des historiques ; résultats déterministes à entrées identiques ; aucune connexion, mutation ou dépendance à un service métier |
| 8E-4.4 | Lecteurs non mutateurs | Autorisation explicite des sources avant accès ; inventaire bidirectionnel D/S/N/fichiers ; prise en compte des scopes et valeurs invalides ; chemins confinés avant lecture ; distinction absent/inaccessible/instable ; hash et taille sur les mêmes octets ; ressources bornées et sources manquantes rapportées ; aucune correction |
| 8E-4.5 | Procédure de réconciliation humaine | Dossier de preuves et hypothèses séparées ; tableau des issues de commit incertain ; aucune sélection par date/hash seul ; décisions documentaires séparées ; réparation non exécutée et soumise à spécification/autorisation distinctes |
| 8E-4.6 | Dispositif isolé et matrice de tests | Tests sans base pour le comparateur/policies ; PostgreSQL jetable pour contraintes, transactions et concurrence ; stockage isolé ; protection avant amorçage contre toute base interdite ; deux routes réelles, fichier absent/altéré, relations, actifs multiples, autorisations, historique, échecs avant/après promotion, deux issues de commit incertain et rejeux idempotents couverts |
| 8E-4.7 | Revue des écarts applicatifs | Décision explicite sur compensation PDF, téléchargement du PDF après dépôt, asymétries des routes, portée du rejeu et lacunes du diagnostic existant ; chaque écart relié aux invariants et aux preuves ; éventuels correctifs séparés du diagnostic et autorisés distinctement |

Pour 8E-4.6, le socle [tests/TestCase.php](../../tests/TestCase.php) exige actuellement `ont_testing`, comme le [test de concurrence](../../Modules/Courrier/tests/Feature/ConcurrenceDepotScanOfficielPostgresTest.php) et ses variables de processus. Le nom de base seul ne prouve pas l'isolation. Ce socle ne doit pas être exécuté dans le cadre actuel ; son adaptation à une cible jetable explicitement autorisée et à un stockage propre doit précéder les futurs tests. Une fixture du comparateur permet de représenter plusieurs actifs sans désactiver les contraintes d'une base partagée ; le test PostgreSQL doit aussi prouver que l'index prévu les refuse.

Les suites [PDF/dépôt](../../Modules/Courrier/tests/Feature/DocumentProduitPdfOfficielTest.php) et [consultation](../../Modules/Courrier/tests/Feature/ConsultationScanOfficielPolicyTest.php) sont des références de couverture, pas des preuves d'exécution dans ce lot. Le test positif de consultation simule le service ; l'échec d'audit après promotion ne simule pas une perte de réponse au commit. Ces limites doivent être levées par des scénarios distincts dans l'environnement futur autorisé.

Acceptation transversale : aucun lot ne conclut à l'absence d'anomalies lorsque des contrôles nécessaires n'ont pas été réalisés ; aucun résultat n'entraîne une réparation automatique, une suppression ou une réécriture d'histoire.
