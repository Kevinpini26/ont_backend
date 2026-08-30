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
