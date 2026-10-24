# Checklist avant mise en production

Liste de vérification finale avant d'ouvrir ce système à l'usage réel de
l'ONT. Chaque section renvoie au document qui détaille le « pourquoi » —
cette checklist ne fait que rassembler le « quoi vérifier, dans quel
ordre ». À dérouler une fois avant le premier déploiement réel, et à
chaque mise à jour majeure.

## 1. Tests et qualité

- [ ] Backend : `php artisan test` entièrement vert (voir `README.md`,
      section Tests, pour le détail de la couverture).
- [ ] Frontend : `npm run test` et `npm run lint` entièrement verts.
- [ ] Analyse statique backend : `./vendor/bin/phpstan analyse` sans erreur.
- [ ] Style de code backend : `./vendor/bin/pint --test` sans écart.
- [ ] Build frontend : `npm run build` réussit et le chunk initial reste
      sous le budget vérifié par la CI (`.github/workflows/ci.yml`).
- [ ] CI des deux dépôts (GitHub Actions) verte sur la branche à déployer.

## 2. Réglages obligatoires (`.env.docker.production`)

Détail complet et justification : `SECURITY.md`, section « Réglages
obligatoires avant mise en ligne ».

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] `APP_KEY` généré et renseigné (jamais la valeur d'exemple)
- [ ] `BACKUP_DISK=backups-s3` avec de vrais identifiants `BACKUP_S3_*`
      (le backend refuse de démarrer sinon, voir
      `BackupDiskProductionGuard`)
- [ ] `FRONTEND_URL` pointe vers le domaine réel du frontend, un seul
- [ ] `VITE_API_BASE_URL` (build frontend) pointe vers l'API réelle en HTTPS
- [ ] `MAIL_MAILER` pointe vers un mailer réel (`smtp`, `ses`,
      `postmark`...), jamais `log` (le backend refuse de démarrer sinon,
      voir `MailProductionGuard`), avec des identifiants SMTP réels
      renseignés et testés (voir `INSTALLATION.md`, §5) — sans ça, aucun
      accusé de réception, aucune notification, aucune réinitialisation de
      mot de passe ne part réellement
- [ ] Coordonnées réelles de l'ONT renseignées depuis l'écran
      "Paramètres de l'organisation" (`organisation.adresse`,
      `organisation.contact_email`, `organisation.contact_telephone`) —
      affichées dans le pied de tous les emails sortants. Les variables
      `ONT_CONTACT_*` ne servent plus que de repli tant qu'aucun de ces
      paramètres n'a été modifié depuis cet écran (voir
      `Modules\Kernel\Models\Setting`, `docs/questions-ont.md`)
- [ ] `SANCTUM_TOKEN_EXPIRATION` et `AUTH_PASSWORD_RESET_EXPIRE` conformes
      à la politique de l'ONT (valeurs par défaut : 480 min / 30 min)
- [ ] HTTPS actif en amont (reverse proxy) sur les deux domaines (API et
      portail)

## 3. Installation et premier démarrage

Suivre `INSTALLATION.md` dans l'ordre. Points de passage obligés :

- [ ] `docker compose --env-file .env.docker.production up -d --build`
      démarré avec `--env-file` (jamais sans, voir le dépannage §8 du
      guide d'installation)
- [ ] `docker compose ps` : tous les services `Up` (backend, frontend,
      base de données, `scheduler`, `queue-worker`)
- [ ] Premier compte administrateur créé (§3 du guide), mot de passe
      temporaire changé dès la première connexion réelle
- [ ] Comptes agents créés depuis **Administration → Utilisateurs**, pas
      par un script ad hoc

## 4. Vérification fonctionnelle (smoke test manuel)

Un passage rapide dans l'interface réelle, pas seulement les tests
automatisés — ils ne couvrent pas l'intégration bout en bout avec un
vrai navigateur et un vrai serveur de mail.

- [ ] Connexion avec le compte administrateur et un compte agent
- [ ] Création d'un courrier entrant (Réception), transmission jusqu'au
      tri (Secrétariat 01)
- [ ] Un e-mail test part réellement (accusé de réception ou
      notification) — vérifier la boîte de réception, pas seulement
      l'absence d'erreur
- [ ] Dépôt public d'une demande de stage, puis suivi de ce dossier via
      le numéro + nom (sans compte)
- [ ] Génération d'un PDF (registre, badge ou fiche stagiaire) pour
      confirmer que `dompdf`/`barryvdh` fonctionne dans le conteneur
- [ ] Export CSV d'une liste (courriers ou stagiaires) pour confirmer
      l'encodage et les en-têtes

## 5. Sécurité

Détail complet : `SECURITY.md`.

- [ ] Aucun compte de démonstration (`Demo#ONT2026`) actif en production
      — ce seeder ne doit jamais tourner hors `APP_ENV=local`
- [ ] Mot de passe administrateur initial changé (jamais
      `ChangeMoi#ONT2026` en usage réel)
- [ ] Procédure « compte compromis » connue de l'équipe (§7 du guide
      d'installation) : réinitialiser le mot de passe **et** révoquer les
      jetons sont deux actions distinctes, les deux nécessaires
- [ ] En-têtes de sécurité HTTP vérifiés sur une vraie requête HTTPS
      (`Strict-Transport-Security` ne part que si la requête est déjà en
      HTTPS, voir `SecurityHeaders`)

## 6. Sauvegardes

- [ ] Sauvegarde automatique quotidienne confirmée active
      (`docker compose logs` autour de 3h du matin)
- [ ] **Une restauration réelle testée au moins une fois**, idéalement
      sur un serveur distinct — jamais la première fois lors d'un
      incident réel (voir `INSTALLATION.md`, §6)

## 7. Suivi après mise en ligne

- [ ] Planificateur et file d'attente surveillés les premiers jours
      (`docker compose logs -f scheduler queue-worker`) — ce sont des
      processus continus, pas des tâches cron système, un crash silencieux
      n'est pas visible autrement
- [ ] Journal d'audit (**Administration → Journal d'audit**) consulté une
      première fois pour confirmer qu'il s'alimente correctement
- [ ] Canal de signalement de sécurité communiqué à l'équipe (voir
      `SECURITY.md`, « Procédure de signalement »)
