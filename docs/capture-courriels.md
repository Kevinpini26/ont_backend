# Courriers reçus par e-mail : conception (non développé)

Statut : **conception seulement**, aucune ligne de code. Cette fonction dépend de la boîte e-mail réelle de
l'ONT (adresse, hébergeur, accès), qui n'est pas encore connue. Ce document décrit ce qui est prévu, ce
qu'il faut sécuriser et ce que l'ONT doit valider avant tout développement.

## 1. Fonctionnement prévu

1. Une boîte dédiée (par exemple `courrier@…`) reçoit les courriers adressés à l'ONT.
2. Un relevé automatique lit les nouveaux messages, par exemple toutes les 5 minutes.
3. Chaque message crée un **brouillon de dépôt à la Réception**. Ce n'est pas encore un courrier :
   - l'expéditeur (nom et adresse), l'objet, le corps du message en texte simple ;
   - les pièces jointes, chacune contrôlée (voir 3), enregistrées dans le stockage privé ;
   - le message d'origine conservé tel quel (fichier `.eml`), pour la preuve.
4. La Réception examine le brouillon et choisit :
   - **Enregistrer** : le courrier est créé avec son **numéro d'arrivée**, attribué **seulement à ce
     moment-là** (origine « externe », canal « courriel »). Il suit ensuite le circuit habituel
     (SEC1, DG, SEC2) et l'expéditeur reçoit l'accusé avec le lien de suivi ;
   - **Écarter** : pour un spam, un doublon ou un message hors sujet, avec un motif obligatoire.
     Rien n'est numéroté, le brouillon reste consultable, puis est supprimé après un délai à fixer.
5. Le message n'est jamais effacé de la boîte par l'application ; il y est seulement marqué comme lu ou
   classé, pour que la boîte reste la source de vérité.

Pourquoi un numéro seulement après vérification : le registre d'arrivée doit rester sans trou ni doublon.
Numéroter chaque e-mail entrant, spams compris, remplirait le registre de numéros « non recevables ».

## 2. Sécurité

- **Accès à la boîte** : compte de service dédié, avec le minimum de droits (lecture et marquage), mot de
  passe ou jeton rangé dans le gestionnaire de secrets du serveur, jamais dans le code ni dans `.env`
  versionné. Connexion chiffrée seulement (IMAPS port 993, ou API de l'hébergeur avec OAuth2).
- **Contenu affiché** : le corps HTML n'est jamais affiché tel quel ; il est converti en texte simple.
  Aucun lien, image distante ni script n'est chargé.
- **Usurpation d'identité** : l'adresse d'expéditeur se falsifie facilement. Le brouillon affiche le
  résultat des contrôles SPF, DKIM et DMARC posés par le serveur de messagerie. Un échec est signalé
  en rouge à la Réception, sans blocage automatique.
- **Journal d'audit** : relevé, création du brouillon, enregistrement et rejet sont tracés.
- **Confidentialité** : les brouillons sont visibles de la Réception seulement, comme un dépôt en ligne.

## 3. Protection contre le spam et contrôle des pièces jointes

- **Spam** : filtrage d'abord par le serveur de messagerie (c'est son rôle) ; l'application ne relève
  que la boîte de réception, pas le dossier « indésirables ». Limite de messages par expéditeur et par
  heure, au-delà de laquelle les brouillons sont regroupés et signalés.
- **Pièces jointes** :
  - types acceptés comme au guichet et en ligne : PDF, JPEG, PNG (les autres sont refusées et signalées) ;
  - type vérifié sur le contenu réel du fichier, pas seulement sur son extension ;
  - même plafond que le courrier (15 Mo par fichier, 20 au plus par message) ;
  - analyse antivirus (par exemple ClamAV) avant tout enregistrement : un fichier infecté n'est jamais
    stocké ni montré, seul le signalement reste ;
  - archives (`.zip`), documents bureautiques avec macros et exécutables refusés.

## 4. Infrastructure nécessaire

- La boîte e-mail de l'ONT et son mode d'accès (IMAP ou API de l'hébergeur).
- Un service de relevé qui tourne en tâche planifiée (le planificateur existe déjà dans le projet).
- Un antivirus sur le serveur (ClamAV : paquet et base de signatures tenue à jour).
- Une bibliothèque PHP pour lire les messages (par exemple `webklex/php-imap`) : nouvelle dépendance,
  à justifier et à valider avant ajout.
- De l'espace de stockage pour les pièces jointes et les messages d'origine, compris dans la sauvegarde
  des fichiers.

## 5. Questions pour l'ONT

1. Quelle adresse reçoit le courrier officiel ? Existe-t-elle déjà, et chez quel hébergeur ?
2. Tous les e-mails reçus sur cette adresse sont-ils des courriers, ou faut-il trier (par exemple ne
   retenir que les messages avec une lettre en pièce jointe) ?
3. Un courrier reçu par e-mail doit-il aussi être imprimé, ou le message suffit-il comme original ?
4. Combien de temps garder les brouillons écartés (spams, doublons) ?
5. Faut-il répondre automatiquement à l'expéditeur dès la réception, avant l'enregistrement ?
6. Qui peut administrer la boîte (mot de passe, transfert, règles) ?

## Hors périmètre : signature électronique certifiée

La signature reste aujourd'hui manuscrite : le document signé est scanné et déposé. Une signature
électronique ayant valeur légale exigera un **prestataire de services de confiance agréé** (certificats
qualifiés, horodatage, conservation des preuves). Ce choix relève de l'ONT et de sa tutelle : aucun
développement n'est prévu tant qu'un prestataire n'est pas désigné.

## Hors périmètre : représentations provinciales

Rien n'est prévu tant que l'ONT n'a pas décidé de leur organisation (boîtes, numérotation propre ou commune,
circuit). Le calendrier de travail gère déjà un seul fuseau horaire (Kinshasa) : un fuseau par province
serait à ajouter le cas échéant.
