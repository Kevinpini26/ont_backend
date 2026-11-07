# Écarts avec les normes d'un système de gestion du courrier (lot 3)

Référence : principes de l'**ISO 15489-1:2016** (gestion des documents d'activité) et usages
administratifs d'un bureau d'ordre. Cette analyse **ne vaut pas certification** : elle compare le
système aux exigences de la norme, à partir du code de la branche `amelioration/lot-3-simplification`
(2026-11-05). Les écarts sont présentés pour décision **avant** tout traitement.

Légende : **En place** · **Partiel** · **Écart**.

| # | Exigence | État | Constat dans le système | Proposition |
|---|---|---|---|---|
| 1 | **Enregistrement** de tout document entrant (registre d'arrivée) | En place | Registre d'arrivée et de départ en PDF, contrôle des ruptures de séquence, sur le numéro d'enregistrement (lot 1C). | — |
| 2 | **Identifiant unique** | En place | Numéro d'enregistrement, d'accusé et de départ uniques (contraintes en base, séquences). | — |
| 3 | **Accusé de réception** au déposant | Partiel | Guichet et portail en ligne. Le portail permet des dépôts massifs et des accusés envoyés à des tiers. | Protections proposées dans `questions-ont.md` (portail public). |
| 4 | **Traçabilité des mouvements** (bordereaux, transmissions) | En place | Bordereaux et transitions jamais réécrits, accusé du destinataire obligatoire. | — |
| 5 | **Instructions et annotations** | En place | Notes de circuit typées, jamais modifiées ni supprimées (correction = nouvelle note). | — |
| 6 | **Échéances et relances** | En place | Échéance de traitement par direction, relances automatiques, relance de l'avis DG après 48 h. | — |
| 7 | **Copies pour information** | En place | Copies tracées, notifiées. | — |
| 8 | **Confidentialité** et contrôle d'accès | En place | Matrice d'accès du lot 2 appliquée et testée. Notifications internes : objet visible des seuls destinataires autorisés, sans test par type de notification. | Ajouter un test par type de notification. |
| 9 | **Classement** (plan de classement, cotes) | Partiel | Cote de classement générée, emplacement physique, statut « classé » puis « archivé ». Pas de **plan de classement** formel (arborescence des séries) validé par l'ONT. | Faire valider un plan de classement par séries (correspondance, stages, actes...). |
| 10 | **Durées de conservation et sort final** (calendrier de conservation) | **Écart** | Aucune durée de conservation par type de document, aucun sort final (conservation définitive ou élimination contrôlée). La règle « un courrier n'est jamais supprimé » est respectée, mais sans calendrier. | Établir un **calendrier de conservation** (durée d'utilité administrative et sort final par série), à faire valider par l'ONT et, le cas échéant, les Archives nationales. Seule exception automatisée aujourd'hui : l'anonymisation des candidatures non retenues (12 mois). |
| 11 | **Intégrité** des documents conservés | Partiel | Empreinte SHA-256 des numérisations et des PDF officiels. Pas de contrôle périodique de ces empreintes, ni d'empreinte pour les pièces jointes. | Commande planifiée de vérification des empreintes, et empreinte calculée au dépôt des pièces jointes. |
| 12 | **Pérennité des formats** | **Écart** | PDF produits par Dompdf, pas au format d'archivage **PDF/A**. | Étudier la conversion PDF/A des documents à conserver longtemps (fiabilité à tester avant). |
| 13 | **Sauvegarde et restauration** | Partiel | Sauvegarde quotidienne de la base et des fichiers, archive contrôlée, vérification de restauration sur base jetable (lot 1C). Les 2 tests réels de restauration sont ignorés faute de droit `CREATE DATABASE`. | Accorder ce droit sur l'environnement de test, ou créer la base de vérification, puis rejouer les tests. |
| 14 | **Recherche** | En place | Recherche plein texte sur l'objet, les numéros et la cote, filtrée par les droits. | — |
| 15 | **Journal d'audit** inaltérable | Partiel | Journal d'audit jamais réécrit par l'application. Aucune protection au niveau de la base : un administrateur de base peut le modifier sans trace. | Interdire la modification et la suppression par un déclencheur PostgreSQL, ou chaîner les entrées par empreinte. |
| 16 | **Métadonnées** minimales (auteur, date, objet, expéditeur, destinataire, niveau de confidentialité) | En place | Toutes saisies à l'enregistrement. | — |
| 17 | **Responsabilités** définies (qui enregistre, qui décide, qui classe) | En place | Règles métier 1 à 9 appliquées par le circuit (Réception, SEC1, DG, SEC2). | — |

## Décisions attendues

1. **Calendrier de conservation** (écart 10) : l'ONT dispose-t-il d'un tableau de gestion ? Sinon,
   faut-il le préparer avec la DFP, SEC1 et SEC2 ?
2. **PDF/A** (écart 12) : faut-il l'exiger pour les courriers signés et les registres ?
3. **Journal d'audit** (point 15) : faut-il le protéger au niveau de la base ?
4. **Plan de classement** (point 9) : faut-il un référentiel des séries ?
