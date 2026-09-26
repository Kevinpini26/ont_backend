# Questions à poser à l'ONT

Points laissés configurables plutôt que figés dans le code, en attendant validation
par la DFP et/ou le Secrétariat Général. Chaque entrée indique où le paramètre
provisoire vit dans le code, pour l'ajuster une fois la réponse connue.

## Lot 1 — courrier, registre

- **Format exact du numéro de départ** (courrier sortant). Provisoire :
  `config('courrier.format_numero_depart')`, valeur par défaut `%d-D%04d`
  (ex. `2026-D0007`), reprenant la forme de `numero_enregistrement` avec un
  `D` distinctif. Voir `Modules\Courrier\Support\DefaultNumeroGenerator::genererNumeroDepart()`.

- **Mentions d'imputation réellement employées par la DG.** Les cinq
  mentions implémentées (pour attribution, pour avis, pour suite utile,
  pour information, pour classement — voir `Modules\Courrier\Enums\MentionImputation`)
  sont celles données dans le brief initial : à confirmer qu'elles couvrent
  bien l'usage réel, ou qu'il n'en manque pas une (ex. "pour signature",
  "pour rappel").

- **Qui a le droit d'imputer un courrier.** Actuellement ouvert à
  l'administrateur et à tout poste du circuit courrier central (voir
  `CourrierPolicy::imputer()`). Le brief décrit la DG comme celle qui
  impute — à restreindre à un poste précis si c'est le cas dans la
  pratique, plutôt que le périmètre large actuel.

- **Règle de cotation du classement physique.** Provisoire :
  `config('courrier.format_cote_classement')`, défaut
  `{direction}-{annee}-{sequence}` (ex. `DFP-2026-0007`), généré
  automatiquement à l'enregistrement (voir
  `CourrierCircuitService::genererCoteClassement()`). À ajuster une fois
  la règle réelle du service courrier connue (existe-t-il déjà des cotes
  papier à faire correspondre ?).

- **Qui signe le registre du courrier.** Le brief mentionne le Secrétaire
  Général — ce poste **n'existe pas** dans `Modules\Kernel\Enums\Poste`
  aujourd'hui (le circuit central s'arrête à DG/DGA/Secrétariat 1/2). Le
  cartouche de signature du registre PDF (voir
  `Modules\Courrier\resources\views\registre.blade.php`) reste donc un
  simple intitulé imprimé, sans champ de signataire réel côté système.
  Faut-il créer ce poste, ou le registre est-il simplement imprimé et
  signé à la main hors du système ?

- **Qui a accès au registre PDF.** Actuellement ouvert à l'administrateur
  et à tout poste du circuit courrier central, même périmètre que les
  statistiques (voir `CourrierPolicy::voirRegistre()`). À restreindre si
  seul un rôle précis (Secrétariat Général, DG) doit pouvoir l'éditer.

- **Niveau de confidentialité : périmètre exact d'accès.** Implémenté :
  un courrier confidentiel/secret est visible par les postes du circuit
  central, l'administrateur, et toute direction explicitement imputée
  (voir `CourrierPolicy::view()`) — une direction qui serait
  origine/destination sans imputation explicite ne le voit pas. À
  confirmer que c'est bien le comportement attendu, notamment pour les
  courriers déjà existants avant l'introduction de ce niveau (tous créés
  "ordinaire" par défaut, donc non affectés).

- **Qui peut créer une délégation de poste.** Implémenté : réservé à
  l'administrateur (voir `CreerDelegationPosteRequest`), sur le même
  principe conservateur que `imputer()`/`voirRegistre()`. En pratique,
  c'est vraisemblablement le titulaire d'un poste (ou son supérieur
  hiérarchique direct) qui devrait pouvoir désigner son propre
  remplaçant en cas d'absence — mais aucune notion de hiérarchie entre
  postes n'existe dans le système pour arbitrer ça automatiquement. À
  confirmer avec la DFP/le Secrétariat Général.

- **Délégation de poste et niveau d'ancienneté/permissions du délégataire.**
  `DelegationPoste` ne vérifie pas que le délégataire a le rôle
  `agent_circuit_courrier` — un utilisateur de n'importe quel rôle peut en
  théorie recevoir une délégation de poste circuit. Est-ce voulu (par
  exemple un responsable de direction couvrant temporairement le
  Secrétariat 02), ou faut-il restreindre aux seuls agents du circuit
  courrier ?

- **Périmètre de la visibilité inter-directions accordée par délégation.**
  `DefaultDirectionScopeBypassResolver` accorde désormais la même
  visibilité "toutes directions" à un délégataire qu'au titulaire du
  poste délégué (voir `DelegationResolver::posteDelegueAujourdhui()`),
  sans quoi il ne pourrait matériellement pas voir les dossiers sur
  lesquels il est habilité à agir. Choix jugé nécessaire, pas neutre : à
  confirmer qu'une délégation ne doit pas être limitée à un sous-ensemble
  de directions.

- **Champs couverts par la recherche plein texte (`recherche_tsvector`).**
  Implémenté sur `objet`, `candidat_nom`, `expediteur_externe_nom`,
  `destinataire_externe_nom`, `reference_expediteur` — pas sur
  `contenu` (corps du projet de réponse, structuré en JSON) ni sur les
  annotations. À étendre si la pratique montre que ces recherches sont
  attendues.

## Points à surveiller pour les lots suivants

- **Colonne `piece_jointe_chemin` sur `courriers`.** Toujours lue par
  plusieurs contrôleurs, ressources et par le frontend (vérifié par grep
  avant d'écrire `create_courrier_pieces_jointes_table`) — coexiste avec
  la nouvelle table `courrier_pieces_jointes` plutôt que d'être remplacée
  d'un coup. Une migration de suppression séparée, et la bascule du
  frontend vers la nouvelle table pour l'affichage des annexes, restent à
  faire une fois confirmé qu'aucun code ne dépend plus de la colonne
  unique.

- **`direction_destination_id` sur `courriers`.** Continue de piloter le
  circuit (routage, quotas, statistiques) — la nouvelle table
  `courrier_imputations` est une lecture additionnelle pour la
  visibilité, pas un remplacement de cette colonne. Une migration des
  données existantes vers une imputation principale équivalente reste une
  étape ultérieure possible, non faite dans ce lot.

## Lot 4 — dossier stagiaire

- **Format du matricule.** Implémenté `%d-STG-%04d` (ex: `2026-STG-0001`),
  attribué à l'affectation (`config('stagiaires.format_matricule')`) — même
  logique que le numéro d'attestation. Aucune convention officielle connue :
  à confirmer, notamment si le matricule doit au contraire être attribué dès
  la réception du dossier plutôt qu'à l'affectation.

- **Maître de stage comme "utilisateur réel".** Implémenté comme un simple
  `maitre_stage_id` (FK nullable vers `users`, n'importe quel rôle), le
  champ texte `maitre_stage` restant disponible quand ce n'est pas un
  utilisateur du système. Aucun rôle `UserRole` dédié n'a été créé : un
  maître de stage n'a donc pas de compte de connexion propre à ce jour, il
  ne peut agir (déposer une fiche de suivi) que s'il possède déjà un compte
  pour une autre raison (ex: responsable de direction). Faut-il un vrai
  rôle "maître de stage" avec ses propres accès, ou est-ce volontairement
  hors périmètre ?

- **Session/promotion.** Simple champ texte libre (`session_promotion`,
  ex: "2026-2027"), sans référentiel ni format imposé — aucune convention
  ONT connue à ce sujet.

- **Qui alimente le référentiel des établissements.** Réservé à la DFP
  (même garde que `gererInformationsComplementaires()`), la liste étant
  simplement consultable par tout utilisateur authentifié pour remplir un
  formulaire. `etablissement_origine` (texte libre) reste disponible en
  parallèle pour un établissement pas encore référencé.

- **Fiche de suivi périodique : qui peut la consulter/créer.** Implémenté :
  la direction d'accueil, le maître de stage lié (`maitre_stage_id`), et la
  DFP. Aucune périodicité minimale n'est imposée (une fiche par semaine ?
  par mois ?) — à préciser si un rythme réglementaire existe.

- **Déclenchement du dépôt du rapport de fin de stage.** Le lien à usage
  unique est généré au moment où la DFP ouvre la période d'évaluation
  (`ouvrirPeriodeEvaluation()`), pas à la clôture (contrairement au retour
  d'expérience, qui est un sondage post-clôture) — hypothèse : le rapport
  doit être disponible avant que l'évaluation ne soit rendue. À confirmer,
  et à préciser si le contenu du rapport doit être formellement rattaché à
  l'évaluation (actuellement un simple document, sans lien structurel avec
  `evaluation_direction_grille`/`evaluation_dfp_grille`).

## Lot 5 — documents officiels manquants

- **Note d'affectation.** Générée automatiquement à l'affectation, stockée
  comme `StagiaireDocument` (type `note_affectation`) — modèle de document
  volontairement simple (identité, direction, date), à faire relire par le
  service compétent comme les autres modèles PDF déjà signalés (voir la
  mention "modèle standard" sur la convention).

- **Badge de stagiaire.** Généré à la demande (jamais stocké, réimprimable
  à tout moment), refusé tant que le stagiaire n'a pas de matricule
  (c.-à-d. avant affectation). Sans photo déposée (type `DocumentType::PHOTO`),
  affiche un encart "Photo non fournie" plutôt que d'échouer — à confirmer
  si la photo doit au contraire être obligatoire avant affectation.

- **Engagement de confidentialité comme document distinct de la
  convention.** Implémenté avec son propre mécanisme de génération et de
  signature (lien public à usage unique, comme la convention), généré au
  même moment que la convention (validation de l'arrivée). La convention
  contient déjà, elle, une clause de confidentialité générale (dans les
  obligations du stagiaire) — les deux coexistent donc : à confirmer que
  ce n'est pas une redondance à supprimer d'un côté ou de l'autre.

- **Certificat de fin de stage distinct de l'attestation.** Généré à la
  clôture, en parallèle de l'attestation existante — ne mentionne jamais
  la note finale, donc consultable par la direction d'accueil (contrairement
  à l'attestation, réservée à `voirEvaluationFinale()`). Aucun mécanisme de
  vérification publique par QR code n'a été ajouté pour ce document
  (contrairement à l'attestation) : à ajouter si ce certificat doit
  également être vérifiable par un tiers.

- **Convention tripartite.** La convention existante mentionne désormais
  l'établissement de formation comme troisième partie et prévoit un
  troisième encart de signature — mais celle-ci reste manuscrite/hors
  système : l'établissement n'a pas de compte utilisateur ONT et aucun
  mécanisme de signature électronique n'a été prévu pour lui. Si
  l'établissement doit pouvoir signer électroniquement (comme le stagiaire
  via lien public), un mécanisme équivalent reste à construire — nécessite
  probablement un contact/email par établissement, absent du référentiel
  `EtablissementFormation` actuel (seulement nom + ville).

## Lot 6 — pilotage et rapport à la tutelle

- **Format de l'export tableur.** Implémenté en CSV (aucune dépendance de
  génération de tableur binaire n'était présente dans le projet — voir
  `composer.json`, seul `barryvdh/laravel-dompdf` existe pour le PDF). Un
  export `.xlsx` natif avec mise en forme réelle nécessiterait d'ajouter
  une librairie dédiée (ex: `phpoffice/phpspreadsheet`) : à faire si un
  fichier tableur natif (pas un CSV) est spécifiquement exigé par la
  tutelle.

- **Colonnes de l'export et filtres disponibles.** Volontairement réduits
  aux filtres déjà exposés par les listes existantes (statut, direction) —
  pas de réplique exhaustive de tous les filtres d'`index()` (ex:
  `recherche`, `periode_debut/fin` pour les courriers). À étendre si
  besoin.

- **Contenu du rapport annuel consolidé.** Limité aux stages *clôturés
  dans l'année* (pas aux dossiers simplement reçus) pour les ventilations
  par direction/type de stage, avec la note moyenne — périmètre exact
  (faut-il aussi les stages encore en cours en fin d'année, un taux de
  réussite avec seuil de note, une ventilation par établissement ?) à
  confirmer avec la DFP/le Secrétariat Général. Généré à la demande (comme
  le registre courrier), pas automatiquement en fin d'année.

- **Qui a accès au rapport annuel.** Restreint à la DFP et à
  l'administrateur (`voirRapportAnnuel()`) — plus étroit que
  `voirStatistiques()` qui inclut aussi un responsable de direction (pour
  ses propres chiffres) et la DG : un rapport consolidé destiné à la
  tutelle n'a, par nature, pas vocation à être vu direction par direction.

## Lot 7 — conformité, preuve et archivage

Voir `docs/conformite-donnees.md` pour l'analyse complète (articles cités
du texte officiel de l'ordonnance-loi n°23/010). Résumé des points qui
dépendent d'une démarche ou d'une décision propre à l'ONT, hors du seul
périmètre du code :

- **Déclaration ou autorisation préalable auprès de l'Autorité de
  protection des données.** Le dossier stagiaire traite une pièce
  d'identité (numéro national d'identification) et une photo (donnée
  biométrique) — l'article 187 soumet ces catégories à une
  **autorisation préalable**, pas une simple déclaration. Aucune démarche
  de ce type n'est visible dans ce qui a été confié à ce code : à
  vérifier en priorité, c'est un préalable légal à toute mise en
  production, pas seulement un point technique.

- **Lieu d'hébergement des données.** L'article 201 impose un stockage
  en RDC (sauf autorisation de transfert). Ce code ne permet pas de
  savoir où est hébergée l'instance de production — à vérifier
  séparément.

- **Délégué à la protection des données et registre des traitements.**
  Aucun des deux n'existe dans l'organisation actuelle (voir articles
  189, 4°, 206 et 227-228) — à trancher si l'ONT souhaite s'en doter,
  notamment pour bénéficier de la dispense de déclaration prévue à
  l'article 189, 4°.

- **Durée de conservation exacte par catégorie de données.**
  L'ordonnance-loi ne fixe aucun chiffre (voir §5 de
  `conformite-donnees.md`) : `config('kernel.mention_information.duree_conservation')`
  reste un texte d'attente, aucune purge automatique n'a été implémentée.
  À chiffrer avec la DFP/le Secrétariat Général, catégorie par catégorie
  (dossier stagiaire non affecté, dossier stagiaire clôturé, courrier,
  journal d'audit).

- **Exercice des droits d'accès/rectification/opposition par la personne
  concernée elle-même.** Aucun point d'entrée self-service n'existe (voir
  §7) — à construire si le volume de demandes le justifie, ou à traiter
  manuellement par la DFP en attendant.

- **Redevance et enregistrement auprès de l'INACO.** L'article 46
  institue une redevance sur les actes/documents publics destinés à être
  archivés, perçue au profit de l'Institut National des Archives du
  Congo — à confirmer si l'ONT s'en acquitte et selon quelles modalités.

- **Signature électronique qualifiée.** Les mécanismes de signature du
  système (clic en application, lien public à usage unique) sont des
  signatures simples, pas qualifiées au sens des articles 104-110 — leur
  valeur probante n'est pas automatiquement équivalente à une signature
  manuscrite. L'empreinte SHA-256 ajoutée dans ce lot n'est qu'une
  preuve technique complémentaire d'intégrité. Une intégration avec un
  prestataire de services de confiance agréé resterait à construire si
  une valeur probante pleine est requise pour ces documents.

## Lot 8 — réalités de terrain

- **Fournisseur SMS.** Aucun fournisseur n'est choisi par l'ONT à ce jour.
  `Modules\Kernel\Support\SmsNotificationCanal` consigne les SMS sans les
  envoyer réellement (`config('kernel.sms.driver') = 'log'`) tant qu'un
  fournisseur n'est pas retenu ; passer à `'http'` et renseigner
  `SMS_HTTP_URL`/les noms de champs une fois un fournisseur choisi (Africa's
  Talking et Twilio sont deux options courantes en Afrique centrale, à
  confirmer selon la couverture réseau des zones concernées).

- **Format des numéros de téléphone.** `Stagiaire::contact` (et
  `Courrier::candidat_contact`) ne distinguent pas email et téléphone à la
  saisie, ni ne valident un format international précis — l'heuristique de
  détection (`SmsNotificationCanal::gere()`) reste simple (8 chiffres ou
  plus, pas un email). Un champ dédié et validé (`+243...`) serait plus
  fiable si le volume de notifications SMS le justifie.

- **Périmètre de la réconciliation hors ligne.** Implémenté seulement pour
  les présences (`POST /stagiaires/{id}/presences/reconciliation`) — le cas
  d'usage le plus manifestement concerné par un mode dégradé (saisie
  d'assiduité en province, sans connexion continue). D'autres écrans
  pourraient avoir le même besoin (annotations de courrier, fiches de
  suivi) : à étendre si la pratique de terrain le confirme, plutôt que
  généraliser sans un besoin identifié.

- **Impression généralisée : périmètre couvert.** Deux nouvelles fiches
  imprimables (`GET /courriers/{id}/imprimer`, `GET /stagiaires/{id}/imprimer`),
  générées à la volée depuis l'état courant du dossier — en plus des
  documents déjà imprimables existants (registre, attestation, convention,
  badge, certificat). D'autres écrans (liste de la file de traitement,
  tableau de bord) pourraient aussi mériter une version imprimable : non
  couvert dans ce lot, à étendre si besoin exprimé.

- **Préparation multi-site : ce qui reste hors périmètre.** Un référentiel
  `Site` existe désormais (`GET/POST /api/v1/sites`) et chaque `Direction`
  peut lui être rattachée (`site_id`, par défaut le siège de Kinshasa) —
  mais **rien d'autre ne dépend du site** dans ce lot : les séquences de
  numérotation (courrier, matricule, attestation), les quotas de
  directions, le routage du circuit courrier et la visibilité des
  utilisateurs restent tous nationaux, sans aucune segmentation par site.
  Une vraie préparation multi-site opérationnelle demanderait de trancher,
  avec la DFP/le Secrétariat Général : les représentations provinciales
  ont-elles leurs propres séquences de numérotation ? Un agent d'une
  représentation provinciale voit-il uniquement les dossiers de son site,
  ou tout le national comme aujourd'hui ? Ce lot pose le référentiel sans
  préjuger de ces réponses.

## Bouclage du circuit courrier (correction du circuit réel vs codé)

- **Décision confirmée — le Protocole ne fait plus partie de
  l'organisation actuelle.** Tout document entrant est créé par la
  Réception, qui remet le bordereau au Secrétariat 01 ; celui-ci en accuse
  réception puis effectue le tri. Le statut `au_protocole` et le poste
  `protocole` restent définis uniquement pour lire les anciens dossiers et
  comptes sans réécrire l'historique. Aucune nouvelle affectation,
  délégation ou action de circuit ne peut les utiliser. Le poste distinct
  `assistant_protocole` est lui aussi strictement historique et doit encore
  être réaffecté par décision métier : il
  reste provisoirement parmi les assistants rédacteurs pour ne pas deviner
  son successeur.

- **Qui attribue réellement le numéro d'accusé de réception ?** Trois
  sources se contredisent : le code le génère au poste Réception (voir
  `config('courrier.poste_creation')`, `CourrierCircuitService::creer()`),
  le document de flux officiel l'attribue au Secrétariat 01 (colonne
  « A/R »), et une note antérieure de l'ONT indique que c'est le
  Secrétariat de la DG qui enregistre la demande et remet le numéro au
  candidat. Les trois désignent peut-être le même guichet physique sous
  des noms différents, mais tant que ce n'est pas confirmé,
  `poste_creation` n'a pas été modifié.

- **Délai d'alerte du tri non effectué : heures d'horloge, pas heures
  ouvrées.** La Direction a demandé "quatre heures ouvrées" pour l'alerte
  d'un courrier resté en `en_attente_tri` sans degré d'urgence renseigné.
  Ce projet n'a pas de calendrier d'heures ouvrées (jours fériés, horaires
  8h30–15h30) implémenté nulle part — le délai indicatif existant
  (`config('courrier.delais_indicatifs_heures')`) traite déjà ses seuils
  comme de simples heures d'horloge, une simplification déjà assumée
  ailleurs dans ce module. `config('courrier.tri.delai_alerte_heures')`
  (défaut 4) suit le même principe : à remplacer par un vrai calcul
  d'heures ouvrées si la pratique de terrain montre que la différence
  compte (ex. un dépôt vendredi après-midi ne devrait pas déclencher
  l'alerte dès le samedi matin).

- **Lot 3 (orientation et dispatch) : le dispatch vers la direction n'est
  déclenché que par un avis favorable sur un courrier déjà imputé.** Choix
  fait faute de mieux pour raccorder l'imputation (existante depuis le Lot
  1 registre, `CourrierPolicy::imputer()`) au circuit sans toucher à la
  mécanique de l'avis DG (favorable/réservé/défavorable), volontairement
  laissée intacte. Dans le circuit décrit par la Direction, l'orientation
  de la DG précède plutôt toute décision — elle a lieu juste après le tri,
  avant même un premier passage devant la DG pour avis. Le modèle
  actuel (`en_attente_avis_dg` -> `en_dispatch`, condition
  `avis_dg_favorable_impute`, voir
  `config('courrier.circuit_transitions.complet.en_attente_avis_dg')`)
  suppose donc que la DG impute et rend son avis en un seul geste ; à
  confirmer si, en pratique, l'imputation doit au contraire pouvoir
  précéder l'avis de plusieurs jours (ex. la DG oriente immédiatement,
  l'avis définitif n'arrivant qu'au retour du tableau de répartition,
  Lot 4).

- **Poste "secrétariat de direction" : rôle nouveau, sans action encore
  définie au-delà de la réception.** Implémenté comme `UserRole::SECRETARIAT_DIRECTION`
  (direction-scopé, comme `RESPONSABLE_DIRECTION`) plutôt que comme un
  `Poste` central. Un courrier arrivé à `chez_direction` (Lot 3) n'a
  aujourd'hui aucune action de circuit qui lui soit propre — il est
  seulement visible par ce poste et par le responsable de la même
  direction, en attente du Lot 4 (tableau de répartition). À confirmer que
  ce rôle est bien distinct du responsable de direction dans la pratique
  (une même personne cumule-t-elle les deux, ou sont-ce deux comptes
  séparés comme modélisé ici ?).

## Lot A/B — tableau généré et verrou de diffusion (ce que le modèle laissait passer)

Questions posées explicitement par la Direction à propos du tableau de
répartition et de l'issue individuelle d'une demande de stage :

- **Combien de tableaux de répartition par période : un seul, ou
  plusieurs ?** Implémenté : plusieurs tableaux autorisés par défaut pour
  une même période (utile pour les compléments de dossiers tardifs, voir
  Lot A point 5 / point 10 des dix manques), avec un paramètre pour
  restreindre à un seul — `config('stagiaires.tableau_un_seul_par_periode')`,
  défaut `false`. Voir `TableauRepartitionCircuitService::creer()`.

- **Qui prononce le refus d'une demande : la DFP dans son tableau, ou la
  DG au feu vert ?** Implémenté : la DFP **propose** le refus (issue
  `non_retenu` + motif) ligne par ligne dans le tableau ; c'est le feu
  vert de la DG (ou son suppléant) qui **prononce** effectivement ce
  refus, dans le même geste que l'approbation des dossiers retenus — un
  refus proposé n'a aucun effet tant que le tableau n'est pas approuvé.
  Voir `TableauRepartitionCircuitService::rendreAvis()` (branche
  `IssueProposee::NON_RETENU` → `StagiaireCircuitService::nonRetenu()`). À
  confirmer que cette répartition des rôles (proposition DFP / décision
  DG) correspond à la pratique, plutôt qu'un refus qui serait de la seule
  autorité de la DFP.

- **Le feu vert est-il une signature, ou une simple mention ?** Non
  tranché dans ce lot : le feu vert reste, comme avant, une approbation
  enregistrée (auteur, date, statut du tableau) sans altérer le PDF ni
  poser une signature visible dessus. Le point 9 des dix manques (sceller
  le feu vert par une empreinte SHA-256 du PDF approuvé) est prévu pour le
  Lot D, où cette question sera reposée dans son contexte technique
  (scellement, non-régénération).

- **Qui donne le feu vert quand la DG est absente en août ?** Implémenté :
  la DGA peut rendre l'avis sur un tableau à la place de la DG lorsque
  celle-ci est marquée indisponible (même mécanisme que l'avis sur un
  courrier simple, voir `DgDisponibilite` et
  `TableauRepartitionCircuitService::rendreAvis()`) — la mention d'intérim
  est enregistrée dans le journal d'audit. Le Lot C généralisera ce
  principe à tous les postes via `DelegationPoste`/`DelegationResolver`
  plutôt que la vérification actuelle, propre à la DG/DGA.

- **Que devient une demande déposée après la soumission du tableau ?**
  Implémenté : elle reste simplement visible parmi les dossiers éligibles
  (`GET /tableaux-repartition/dossiers-eligibles`) et peut être ajoutée à
  un **autre** tableau de la même période (voir la question précédente sur
  la pluralité des tableaux) — aucun mécanisme de "liste d'attente"
  formelle distinct des dossiers éligibles n'a été créé. Le point 10 des
  dix manques (demandes arrivées après la clôture officielle de la
  période) reste, lui, entièrement ouvert : ce lot ne distingue pas une
  période "close" d'une période encore ouverte à de nouveaux tableaux.

- **Le projet de réponse rédigé par un assistant est-il relu avant
  validation DG ?** Question héritée d'un lot antérieur (rédaction
  assistée de courrier sortant), sans rapport direct avec le tableau de
  répartition — non retranchée ici faute de nouvel élément dans ce lot
  pour y répondre ; consigner la réponse dans la section Lot 1 le jour où
  elle est connue.

Questions supplémentaires découvertes en implémentant (Lot A/B) :

- **Imputation automatique vers la DFP à l'avis favorable.** Un courrier
  de demande de stage qui reçoit un avis favorable de la DG, sans que
  celle-ci ait explicitement imputé le dossier, est désormais imputé
  automatiquement à la DFP (sinon aucune demande de stage ne devient
  jamais éligible au tableau). Provisoire :
  `config('stagiaires.imputation_automatique_dfp')`, défaut `true`. Voir
  `CourrierCircuitService::rendreAvisDg()`. À confirmer que l'avis
  favorable de la DG vaut bien, dans tous les cas, orientation vers la
  DFP — ou si un avis favorable peut aussi orienter vers une autre
  direction sans passer par un tableau de répartition.

- **Liste des motifs de refus.** Implémentée comme une liste fermée
  configurable plutôt qu'un texte entièrement libre (pour permettre des
  statistiques de refus) : `places_epuisees`, `dossier_incomplet`,
  `profil_sans_correspondance`, `hors_periode` — voir
  `config('stagiaires.motifs_non_retenu')` — complétée d'un champ texte
  libre facultatif pour préciser. À confirmer que ces quatre motifs
  couvrent bien les cas réels, ou qu'il n'en manque pas un.

- **Canal et ton de la notification de refus au candidat.** Le message
  envoyé (`IssueTableauNotification`) reste un modèle générique reprenant
  le motif choisi, jamais relu par un humain avant envoi (contrairement à
  la question ci-dessus sur le projet de réponse) — à faire valider par
  la DFP comme les autres modèles de documents/courriers déjà signalés
  dans ce fichier (ton, formule de politesse, mention des voies de
  recours éventuelles).

- **Traçabilité réelle de l'état de remise d'un SMS.** Le suivi de
  diffusion (`notifications_diffusion.statut_remise`) enregistre l'envoi
  côté ONT (`envoye`), mais tant que
  `config('kernel.sms.driver')` reste à `'log'` (aucun fournisseur SMS
  retenu — voir la question déjà posée au Lot 8), aucun accusé de remise
  réel ne peut être obtenu : le champ restera à `envoye` sans jamais
  passer à un état "livré"/"échoué" tant qu'un fournisseur n'est pas
  branché.

## Lot C — Réception tenable et tri corrigible

- **La transmission par lot ne concerne, dans ce lot, que le passage
  "recu → Secrétariat 01".** En creusant le circuit réel (voir
  `config('courrier.circuit_transitions')`), la Réception n'est
  destinataire d'aucune transmission après la création d'un courrier —
  seule `representerDg()` (re-présentation après un avis "réservé") la
  concerne à nouveau. Le vrai point de blocage identifié est donc en
  aval : le Secrétariat 01 (ou le Protocole) devait jusqu'ici accuser
  réception de chaque courrier fraîchement créé UN PAR UN avant de
  pouvoir commencer à les traiter. `BordereauLot` regroupe donc des
  transitions déjà existantes (jamais de nouvelle transition créée) sous
  un même bordereau, homogène par poste destinataire, débloqué en une
  seule décharge — voir `CourrierCircuitService::grouperEnBordereauLot()`/
  `accuserReceptionLot()`. À confirmer que c'est bien ce goulot
  d'étranglement précis (et pas un autre, plus tôt dans le circuit) que
  la Direction avait en tête en désignant "la Réception" comme point de
  blocage.

- **Qui peut grouper des dossiers en un bordereau de lot.** Ouvert à tout
  poste du circuit courrier central (voir `BordereauLotPolicy::creer()`)
  plutôt que restreint à un poste précis : l'opération elle-même ne
  change aucun état (elle étiquette des transitions déjà en attente), le
  service vérifie déjà leur homogénéité — à restreindre si un contrôle
  plus strict s'avère nécessaire en pratique.

- **Réorientation du tri : seul le duo DG/DGA peut la déclencher.**
  Implémenté avec la même garde que l'avis DG (poste propre, délégation
  de poste, ou DGA quand la DG est marquée indisponible) — jamais un
  autre poste du circuit, même s'il détient le dossier à un autre moment.
  Portée volontairement restreinte à la seule transition
  `en_attente_avis_dg → en_attente_tri` (le seul cas concret décrit) : à
  étendre si d'autres points du circuit doivent aussi pouvoir "revenir en
  arrière sans faute".

- **Statistique de justesse du tri : par agent, jamais nominative dans
  l'absolu.** Le calcul (`GET /courriers/justesse-tri`) expose le nom de
  chaque agent ayant trié à côté de son taux — visible du seul
  Secrétariat 01 (et de l'administrateur), jamais de la DG ni d'une
  direction. À confirmer que cette granularité par agent (plutôt qu'un
  taux global anonyme) correspond à l'intention réelle ("objectiver un
  taux", pas "noter" un agent précis).

- **Suppléance généralisée du tableau de répartition : la DGA reste un
  cas à part de `DelegationPoste`.** Le mécanisme générique
  (`DelegationResolver`) couvre désormais aussi bien la Réception que le
  poste DG pour le circuit du tableau — mais l'intérim DG/DGA continue de
  reposer sur `DgDisponibilite` (un simple drapeau, pas une délégation
  datée), conformément à une décision antérieure documentée dans le code
  ("son mécanisme spécifique... reste en l'état"). Les deux mécanismes
  coexistent donc pour ce seul poste : à unifier si la pratique montre
  que l'un des deux devient redondant.

- **Délais indicatifs du dossier stagiaire : valeurs provisoires.**
  `config('stagiaires.delais_indicatifs_heures')` (dossier reçu 48h, en
  attente d'affectation/affecté 72h, évaluation en cours 168h) reprend
  l'ordre de grandeur du courrier plutôt qu'un délai réglementaire connu
  — à ajuster avec la DFP. Contrairement au courrier (dont l'historique
  complet vit dans `courrier_transitions`), un unique
  `Stagiaire::statut_change_at` sert de repère (voir
  `StagiaireEnSouffrance`) : suffisant pour "depuis quand dans l'étape
  courante", mais ne conserve pas un historique complet des passages
  antérieurs si ce besoin apparaissait plus tard.

## Lot D — classement retrouvable et cohérence de la boucle

- **Cote de la lettre : posée à l'issue, pas avant.** Une demande de stage
  ne recevait jusqu'ici jamais de cote de classement propre (elle sort du
  circuit courrier ordinaire dès son imputation à la DFP, sans jamais
  atteindre `enregistrer()`) — `classerDemandeStage()` la pose désormais
  à l'approbation du tableau qui la porte, retenue ou non. Séquence
  dédiée (`classement_stagiaire`), indépendante de `numero_enregistrement`
  (qui n'existe jamais pour ces courriers) : à confirmer que cette
  cadence (une cote par lettre, au moment où son sort est tranché) suffit,
  ou si un classement plus précoce est attendu (dès la réception, avant
  même l'examen par la DFP).

- **Cote du tableau : même séquence dédiée, pas liée à celle des
  lettres.** `classement_tableau` est une séquence indépendante de
  `classement_stagiaire` — un tableau et les dossiers qu'il porte ont
  chacun leur propre numérotation, sans lien arithmétique entre les deux
  (contrairement à Courrier où cote et numéro d'enregistrement partagent
  la même séquence). À confirmer que c'est bien l'usage attendu.

- **Scellement : une table dédiée, mais aucune application ne bloque
  une modification directe en base.** L'immutabilité de
  `tableau_repartition_scellements` repose sur l'absence de toute méthode
  applicative de mise à jour (aucun endpoint, aucun service ne la
  modifie après création) — pas sur une contrainte PostgreSQL (trigger,
  colonne générée) qui interdirait une modification manuelle en base.
  Cohérent avec le reste du projet (`courrier_transitions`,
  `notifications_diffusion` sont "append-only" par la même convention),
  mais à renforcer par un vrai verrou base de données si la valeur
  probante de cette preuve devait un jour être contestée devant un tiers.

- **Cohérence nocturne : rapporte, ne corrige jamais.** La commande
  `stagiaires:verifier-coherence` (02h00) journalise chaque anomalie via
  le journal d'audit existant (`coherence.anomalie_detectee`, consultable
  depuis l'écran d'administration) plutôt que par un nouveau canal de
  notification dédié — à ajouter (email/SMS à l'administrateur) si le
  simple journal d'audit ne suffit pas à garantir qu'une anomalie sera
  vue à temps. Les cinq vérifications couvrent les scénarios cités
  explicitement ; d'autres incohérences pourraient exister sans être
  détectées par cette première version.

## Rédaction rendue aux assistants, classeur d'attente créé

(Nommage volontairement non numéroté — la Direction a demandé ces deux
évolutions sous les termes "lot 2" et "lot 3", mais `config.php`/`CourrierStatut`
utilisent déjà "Lot 2"/"Lot 3" pour un chantier antérieur distinct, le tri par
urgence et le dispatch d'imputation : réutiliser les mêmes numéros ici aurait
prêté à confusion dans les commentaires de code.)

- **« Le Secrétariat 01 garde le tri et récupère l'établissement des accusés
  de réception » — traduit ici comme une simple reformulation, pas comme un
  changement fonctionnel.** `numero_accuse_reception` est généré
  automatiquement à la création du courrier (`CourrierCircuitService::creer()`
  et les méthodes `creerDepuisPublic()`/`creerCourrierExterneDepuisPublic()`/
  `creerCourrierSortant()` apparentées), sans geste humain distinct — y
  compris pour un dépôt du portail public, où aucun agent n'intervient. Rien
  dans le code actuel ne fait de cette génération une action "détenue" par un
  poste qu'on pourrait "retirer" puis "rendre" à un autre. Faute d'une
  définition plus précise de ce que serait ce geste distinct (une lettre
  d'accusé de réception envoyée à l'expéditeur ? une reprise en main d'une
  réimpression de la feuille de couverture, voir
  `CourrierController::feuilleCouverture()` ? autre chose ?), ce lot n'a
  changé ni la génération automatique du numéro, ni l'envoi des mails
  `AccuseReceptionCandidatMail`/`AccuseReceptionCourrierExterneMail` — modifier
  ce mécanisme risquerait de casser le parcours déjà livré et testé du portail
  public. À confirmer avec la Direction ce que ce geste doit concrètement
  devenir ; si une action distincte est attendue, elle reste à construire.

- **« Le classeur d'attente... on en repart vers le signataire » — traduit
  comme un retour vers la file d'avis de la DG, pas vers la signature.**
  `EN_ATTENTE_CLASSEUR` (voir `CourrierStatut`) est atteint depuis le tri
  quand `degre_urgence` vaut `normal`, et en repart vers `EN_ATTENTE_AVIS_DG`
  via `CourrierCircuitService::transmettreDepuisClasseur()` — jamais
  directement vers `SIGNE`, qui suppose une rédaction, une relecture validée
  et un projet de réponse déjà écrits, aucun desquels n'existe encore à ce
  stade du circuit pour un dossier qui vient tout juste du tri. Lu comme "le
  signataire" désignant la DG dans le sens où le vocabulaire administratif de
  l'ONT semble l'employer pour l'avis/l'arbitrage, pas littéralement
  `Courrier::signataire_id`. À confirmer ce point avec la Direction si
  l'intention était différente (un chemin direct vers la signature existerait
  alors à construire séparément).

- **Rédacteur et relecteur du projet de réponse : n'importe lequel des
  quatre postes assistants pour chaque rôle, aucune règle d'affectation par
  type de dossier.** `soumettreProjetReponse()` accepte
  `ASSISTANT_1`/`ASSISTANT_2`/`ASSISTANT_DGA`
  indifféremment comme rédacteur (voir
  `config('courrier.circuit_transitions.complet.projet_a_rediger')`), et le
  relecteur reste un identifiant choisi librement à la soumission (`relecteur_id`),
  comme avant ce lot — aucune répartition du travail entre les quatre
  assistants (par direction, par type de courrier...) n'est imposée. À
  préciser si la pratique réelle attribue les dossiers différemment.
