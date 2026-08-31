# Conformité au Code du numérique (RDC)

Référence : **ordonnance-loi n°23/010 du 13 mars 2023 portant Code du
numérique**, telle que promulguée et publiée (texte officiel consulté
directement, pas un résumé de doctrine — voir la note méthodologique en
fin de document). Les numéros d'article cités ci-dessous renvoient à ce
texte. Ce document sert de référence pour les choix déjà faits dans le
code (implémentés dans ce lot ou les précédents) et pour les points qui
restent à trancher avec la DFP / le Secrétariat Général — ces derniers
sont repris dans `docs/questions-ont.md`.

## 1. Champ d'application

L'article 184, 1° soumet explicitement au Titre III (Des données
personnelles) « la collecte, le traitement, la transmission, le stockage
et l'utilisation des données à caractère personnel par l'État, la
Province, les Entités Territoriales Décentralisées et Déconcentrées, les
personnes morales de droit public ou de droit privé et les personnes
physiques ». **L'ONT, établissement public, est donc pleinement soumis à
ce titre** — ce n'est pas une question d'interprétation.

## 2. Déclaration ou autorisation préalable auprès de l'Autorité de protection des données

- Article 186 : tout traitement de données personnelles est en principe
  soumis à une **déclaration préalable** auprès de l'Autorité de
  protection des données (récépissé délivré sous 30 jours, prorogeable
  une fois de 30 jours — article 190 ; le silence de l'Autorité passé ce
  délai vaut acceptation).
- Article 187 : une **autorisation préalable** (pas une simple
  déclaration) est requise pour, entre autres, le traitement portant sur
  des **données biométriques** ou sur **un numéro national
  d'identification ou tout identifiant de même nature (y compris un
  numéro de téléphone)**.

**Point de vigilance direct pour ce système** : le dossier stagiaire
collecte une pièce d'identité (`DocumentType::PIECE_IDENTITE`, qui porte
un numéro national d'identification) et, depuis ce lot, une photo
(`DocumentType::PHOTO`, donnée biométrique au sens de l'article 183, 1°).
**Ces deux catégories relèvent donc probablement du régime de
l'autorisation préalable (article 187), pas de la simple déclaration.**
Aucune démarche de déclaration ni d'autorisation auprès de l'Autorité de
protection des données n'a été effectuée à ce jour côté ONT, à la
connaissance de ce code — c'est une démarche administrative externe au
système, mais son absence expose l'ONT à un traitement non conforme. À
vérifier en priorité avec la DFP / le Secrétariat Général.

## 3. Localisation des données (article 201)

« Les données personnelles sont stockées et/ou logées en République
Démocratique du Congo. » Un transfert vers un État tiers ou une
organisation internationale n'est licite qu'après autorisation de
l'Autorité de protection des données et sous réserve d'un niveau de
protection équivalent (articles 201-202). **À vérifier concrètement :
où est hébergée la base de données de production de ce système** (le
présent code ne permet pas de le savoir) — si l'hébergement est hors
RDC, une régularisation est nécessaire.

## 4. Principes de traitement (articles 192 à 195)

Licéité (consentement ou obligation légale), respect de la dignité et de
la vie privée, loyauté et transparence, minimisation, exactitude,
sécurité (article 193). Le traitement de catégories sensibles (opinions
politiques, convictions religieuses, santé, vie sexuelle...) est
interdit sauf exceptions limitativement énumérées (article 195) — non
concerné par les données actuellement collectées par ce système.

## 5. Durée de conservation (article 193, 3°)

**L'ordonnance-loi n'impose aucune durée fixe en années.** Le principe
est que les données sont conservées « pendant une durée n'excédant pas
celle nécessaire à la réalisation des finalités pour lesquelles elles
sont collectées », avec une exception pour une conservation plus longue
à des fins archivistiques d'intérêt public, sous réserve de la **Loi
n°78-013 du 11 juillet 1978 portant régime général des archives**
(citée par le Code du numérique lui-même à l'article 193, 3° — texte
distinct, non consulté dans le cadre de ce lot).

**Implémenté dans ce lot** : `config('kernel.mention_information.duree_conservation')`
porte cette information (actuellement un texte expliquant l'absence de
durée fixe et renvoyant à une confirmation DFP), plutôt qu'une durée
inventée. **Aucune purge automatique n'a été implémentée** — il aurait
fallu une durée concrète à appliquer, qu'aucun texte ne fixe pour ce cas
d'usage précis ; voir `docs/questions-ont.md`.

## 6. Mention d'information (article 220)

L'article 220 énumère limitativement ce qui doit être porté à la
connaissance de la personne concernée « au plus tard lors de la
collecte » : identité du responsable du traitement, finalités,
catégories de données, destinataires, droit de ne plus figurer au
fichier, droit d'opposition à la prospection, caractère obligatoire ou
non de la réponse, droit d'accès et de rectification, droit de retrait du
consentement, droit de réclamation auprès de l'Autorité, durée de
conservation, existence d'une décision automatisée, éventualité d'un
transfert vers un État tiers.

**Implémenté dans ce lot** : `config('kernel.mention_information')`
reprend ces points un par un, exposé publiquement via
`GET /api/v1/public/mention-information` (`MentionInformationController`)
pour affichage sur les formulaires publics de dépôt (demande de stage,
courrier externe). Le contenu exact (destinataires précis, durée de
conservation) reste à valider par la DFP.

## 7. Droits de la personne concernée (articles 209 à 214)

- Droit d'accès (article 209) : réponse due sous 60 jours (article 210).
- Droit d'opposition (article 213) : réponse due sous 30 jours.
- Droit de rectification ou d'effacement (article 214) : réponse due
  sous 30 jours.
- Droit à la portabilité (article 211), sauf pour un traitement
  nécessaire à une mission d'intérêt public — ce qui couvre
  vraisemblablement l'essentiel des traitements de l'ONT.

**Écart non comblé dans ce lot** : le système ne propose aujourd'hui
**aucun point d'entrée self-service** pour qu'un candidat, un stagiaire
ou un expéditeur externe exerce l'un de ces droits (l'accès aux propres
documents existe pour les agents ONT via les policies, pas pour la
personne concernée elle-même). Dans l'attente d'un tel mécanisme, ces
demandes devraient être traitées manuellement par la DFP — à confirmer
comme procédure transitoire, et à outiller si le volume le justifie.

## 8. Obligations du responsable du traitement (article 219)

Contrôle d'accès par fonction, journalisation, sauvegardes, formation des
agents — déjà largement couvert dans ce système par les Policies
Laravel, le `CourrierDirectionScope`/le Global Scope de `Stagiaire`, et
`AuditLogger` (voir les lots précédents). **Non couvert** : la
désignation formelle d'un **délégué à la protection des données**
(mentionné aux articles 189, 4° et 206) et la tenue du **registre des
traitements** que ce délégué met à la disposition de l'Autorité (articles
227-228) — aucun des deux n'existe dans ce système ni dans l'organisation
décrite. À signaler à la DFP/au Secrétariat Général : la dispense des
formalités de déclaration prévue à l'article 189, 4° est précisément
conditionnée à la désignation de ce délégué.

## 9. Écrit électronique et signature électronique (articles 88 à 110)

- L'écrit électronique a la même valeur juridique que l'écrit papier
  (article 89), et la même force probante que l'écrit papier légalisé
  ayant date certaine s'il est horodaté et revêtu d'une signature
  électronique **certifiée** (article 91).
- La signature électronique peut être **simple** ou **qualifiée**
  (article 104). Seule la signature **qualifiée**, liée à un certificat
  électronique qualifié délivré par un prestataire de services de
  confiance, a la même force probante que la signature manuscrite
  (article 108) et bénéficie d'une présomption de fiabilité (article
  107). Une signature simple n'a explicitement aucune de ces deux
  garanties dans le texte.

**Constat direct pour ce système** : les mécanismes de « signature »
déjà construits (validation en un clic dans l'application pour un
courrier, ou via lien public à usage unique pour la convention de stage
et l'engagement de confidentialité) **ne constituent pas des signatures
électroniques qualifiées** au sens des articles 104-110 — il n'existe ni
certificat, ni prestataire de services de confiance, ni dispositif
sécurisé de création de signature. Leur valeur probante n'est donc
**pas automatiquement équivalente** à une signature manuscrite au regard
de ce texte. C'est précisément la raison pour laquelle ce lot ajoute une
**empreinte SHA-256** (voir `Modules\Kernel\Support\EmpreinteFichier`) sur
chaque document PDF généré ou signé (courrier signé, attestation,
certificat, note d'affectation, convention, engagement de
confidentialité) : une preuve technique **complémentaire** qu'un fichier
donné n'a pas été altéré depuis sa génération, pas un substitut à une
signature qualifiée. Si une valeur probante pleine et entière est
requise, une intégration avec un prestataire de services de confiance
agréé resterait à construire — décision hors du périmètre technique de
ce lot.

## 10. Archivage électronique et archives numériques publiques (articles 42 à 46)

- Article 42-43 : un document électronique archivé doit rester
  accessible, conservé sous une forme non altérable, avec ses métadonnées
  de traçabilité (origine, destination, date, heure).
- Article 45 : l'**Institut National des Archives du Congo (INACO)**
  assure l'encadrement et la régulation de la gestion des archives
  électroniques des services publics, et leur apporte assistance et
  conseil.
- Article 46 : une **redevance** est instituée sur les actes et
  documents émis par les services et établissements publics destinés à
  être archivés, dont le taux et les modalités de perception sont fixés
  par arrêté interministériel.

**Implémenté dans ce lot** : un export d'archive annuelle
(`GET /api/v1/archives/annuelle?annee=AAAA`, réservé à la DFP et à
l'administrateur) regroupe dans une seule archive ZIP le registre
courrier (arrivée et départ) et le rapport annuel consolidé des
stagiaires de l'année demandée — pensé comme le paquet remis à la
tutelle ou à l'INACO en cas de contrôle.

**Non tranché** : si l'ONT doit effectivement s'acquitter de la
redevance d'archivage prévue à l'article 46, ou s'enregistrer/transmettre
ses archives numériques à l'INACO selon des modalités précises — ce sont
des démarches administratives externes au système, à confirmer avec la
DFP/le Secrétariat Général.

## Note méthodologique

Le texte de l'ordonnance-loi a été obtenu et lu directement (pas un
résumé de doctrine tierce) : une première source consultée
(`leganet.cd`) s'est révélée être un article de doctrine commentant le
texte, pas le texte lui-même — écartée après vérification du contenu. Le
texte effectivement cité ci-dessus provient d'une copie numérisée/OCRisée
de l'ordonnance-loi officielle (Cabinet du Président de la République),
recoupée avec sa structure en livres/titres/chapitres. Les articles cités
ont été relus dans ce texte avant rédaction de ce document ; aucune règle
ci-dessus n'est inventée ou extrapolée au-delà de ce que le texte énonce.
