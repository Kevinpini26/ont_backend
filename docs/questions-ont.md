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
