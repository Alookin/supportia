# Zeno : portail de support assisté par IA

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-17-336791?style=flat-square&logo=postgresql&logoColor=white)
![OpenAI](https://img.shields.io/badge/OpenAI-gpt--5.4--mini-412991?style=flat-square)

Zeno permet à des utilisateurs non techniques (commerciaux, marketing, services généraux…) de créer des tickets GLPI en langage naturel. Un moteur d'IA classe la demande, propose une catégorie et une priorité et rédige une description structurée. Zeno crée ensuite le ticket dans GLPI par son API REST.

Par défaut, le moteur d'IA est OpenAI `gpt-5.4-mini`. Si l'appel échoue, une classification par mots-clés prend le relais, sans erreur affichée à l'utilisateur.

> Le dépôt s'appelle `supportia`, nom historique du projet. Le produit s'appelle Zeno.

---

## Pourquoi Zeno ?

Dans un contexte de support terrain avec 15 commerciaux itinérants, **93 % des tickets remontés ne contenaient ni catégorie, ni priorité, ni description structurée**. Les techniciens passaient un temps considérable à qualifier chaque demande avant de pouvoir la traiter.

Les outils de ticketing classiques (GLPI, Jira, Redmine) sont pensés pour des profils techniques. La saisie des catégories ITSM, des priorités et de descriptions structurées y est perçue comme une contrainte sans valeur ajoutée.

Zeno supprime cette friction : l'utilisateur décrit le problème comme dans un message, et Zeno se charge de la qualification, de la structuration et de la création dans GLPI.

---

## Fonctionnalités

### Création d'un ticket
- **Saisie en langage naturel** : 20 caractères minimum, 5 000 maximum. Les textes inexploitables (trop peu de mots, caractères répétés, mots vides) sont refusés avec un message explicatif.
- **Classification par IA** : catégorie, priorité (1 à 5), titre et description structurée, avec un score de confiance.
- **Validation par l'utilisateur** : sous le seuil de confiance (0,7 par défaut), l'utilisateur relit et corrige la proposition (titre, catégorie, priorité, description) avant l'envoi, ou l'annule. Au-dessus du seuil, le ticket part directement.
- **Fallback par mots-clés** : si le moteur échoue (clé absente, HTTP, timeout, réponse illisible), Zeno classe par mots-clés. La confiance qui en résulte (0,35 au plus) est sous le seuil par défaut : l'utilisateur valide donc la proposition. L'interface l'affiche comme « Analyse simplifiée », et non « Analyse IA ».
- **Plusieurs clients par ticket** : identifiant et nom, jusqu'à 10. Zeno signale les tickets ouverts sur le même client dans les 30 derniers jours, pour éviter les doublons.
- **Pièces jointes** : jusqu'à 5 fichiers par ticket et 1 par réponse, 10 Mo chacun (`SUPPORTIA_ATTACHMENT_MAX_KB`). Formats : images (jpg, png, gif, webp), PDF, CSV, TXT, LOG, Excel (xls, xlsx). Les limites et les formats viennent de `config/supportia.php` (`attachments`), seule source pour la validation et les formulaires. Les fichiers sont stockés hors de `public/`, servis par une route authentifiée, et ceux joints à la création sont envoyés dans GLPI comme documents du ticket.
  > **Pour que la limite de 10 Mo soit réelle**, le serveur doit laisser passer les fichiers, sinon ils sont refusés avant Zeno : `upload_max_filesize = 10M` et `post_max_size = 52M` (5 fichiers de 10 Mo et le formulaire) dans PHP, et `client_max_body_size 52m;` dans nginx (défaut : 1 Mo, au-delà duquel nginx répond 413 avant PHP).
- **Catégories par équipe** : une catégorie rattachée à une ou plusieurs équipes n'est proposée qu'à leurs membres. Les autres catégories sont proposées à tous.

### Intégration GLPI
- **Création par l'API REST** : session (`initSession`, jeton mis en cache 30 min et renouvelé après un 401), puis `POST /Ticket`. Le demandeur est renseigné par son `glpi_user_id` (`_users_id_requester`) et, quand un email est connu, par `_users_id_requester_notif`.
- **Reprise si GLPI est indisponible** : le ticket passe en file d'attente et le job `CreateGlpiTicket` le renvoie, avec 5 tentatives espacées de 1 min à 1 h. Après la dernière tentative, le ticket est marqué en échec.
- **Suivi** : statut GLPI, technicien assigné et suivis publics, affichés sur le détail du ticket. Les données sont mises en cache 2 minutes et relues au chargement de la page. Les statuts des tickets ouverts sont aussi synchronisés toutes les 10 minutes.
- **Réponses de l'utilisateur** : elles sont envoyées dans GLPI comme suivis (`POST /ITILFollowup`). Le fichier joint à une réponse reste dans Zeno ; le suivi GLPI le mentionne seulement.
- **Clôture par l'utilisateur** : le bouton « Le problème est résolu » envoie une solution dans GLPI (`POST /ITILSolution`).
- **Email « ticket résolu »** : envoyé une seule fois au demandeur quand GLPI passe le ticket en résolu. Il se désactive avec `ZENO_NOTIFY_RESOLVED=false`.
- **Délai habituel** : médiane du temps de résolution GLPI de la catégorie sur 12 mois, recalculée chaque nuit. Elle n'est pas affichée sous 5 tickets résolus ni au-delà de `ZENO_ESTIMATE_MAX_HOURS` (120 h).

### Interface
- **Conversation** façon messagerie sur le détail du ticket : réponses GLPI à gauche, celles de l'utilisateur à droite, une pièce jointe possible par message.
- **Mes tickets** : filtres Tous, En cours (par défaut), Résolus et En attente.
- **Tickets de l'équipe** et **tableau de bord** (volumes, répartition par catégorie et priorité, taux de classement automatique, justesse de l'IA par rapport à la catégorie finale dans GLPI). Ces pages sont réservées aux admins d'équipe et aux admins.
- **Thème sombre** par défaut, avec un thème clair au choix.
- **Interface en français** : textes des vues en français, traductions Laravel dans `lang/fr/`.

### Rôles et équipes

Chaque utilisateur appartient à une organisation et, éventuellement, à une équipe (Commercial, Marketing…).

| Rôle | Valeur | Voit |
|---|---|---|
| Membre | `member` | ses propres tickets |
| Admin d'équipe | `team_admin` | les tickets de son équipe et les siens, la page équipe et le tableau de bord de l'équipe |
| Admin | `admin` | tous les tickets de l'organisation et le tableau de bord global |

Un admin d'équipe sans équipe a les droits d'un membre. Seuls l'auteur et les admins peuvent valider une proposition. Les comptes se gèrent en ligne de commande (`zeno:user-create`, `zeno:user-update`).

### Organisations

Chaque organisation a sa propre instance GLPI (URL et jetons chiffrés en base), son jeu de catégories et ses équipes. Le logo et la couleur de la page de connexion viennent de la **première organisation active**. Ce branding n'est donc pas encore propre à chaque organisation.

---

## Moteurs d'IA

| `AI_PROVIDER` | Moteur | Timeout |
|---|---|---|
| `openai` (défaut) | API OpenAI, `/v1/chat/completions`, `gpt-5.4-mini` | `OPENAI_TIMEOUT`, 10 s |
| `claude` | API Anthropic, `claude-sonnet-4-20250514` | `SUPPORTIA_AI_TIMEOUT`, 25 s |
| `local` | Serveur auto-hébergé compatible OpenAI (Ollama, LM Studio). **Interdit en production** | `SUPPORTIA_AI_TIMEOUT`, 25 s |

Quel que soit le moteur, un échec bascule sur le fallback par mots-clés. Une valeur inconnue d'`AI_PROVIDER` (faute de frappe, majuscule, valeur vide) retombe sur `claude` et est journalisée en `warning` (« AI_PROVIDER inconnu, repli sur claude »). Avec OpenAI, une clé absente, refusée (401) ou un quota épuisé (429) est journalisé au niveau `error`, avec un préfixe dans `ai_request_logs.error` : `[OPENAI_KEY_MISSING]`, `[OPENAI_KEY_REJECTED]` ou `[OPENAI_QUOTA_EXCEEDED]`.

### Coût mesuré

Mesuré sur des classifications réelles avec `gpt-5.4-mini`, environ 3 300 tokens par appel (dont environ 3 000 en entrée) :

| Cas | Coût par classification | Pour 1 000 tickets |
|---|---|---|
| Sans cache | ~0,0017 $ | ~1,7 $ |
| Avec cache d'entrée OpenAI | ~0,0007 $ | ~0,7 $ |

Temps de réponse observé : 2,2 à 2,9 s par classification. Les tarifs utilisés pour le calcul sont dans `config/supportia.php` (`ai_pricing`). Ils viennent d'une source tierce et restent à confirmer dans le tableau de bord OpenAI.

---

## Observabilité : `ai_request_logs`

Chaque appel au moteur d'IA laisse une ligne dans `ai_request_logs`, y compris en cas de fallback :

- **attribution** : organisation, utilisateur, équipe. Elle survit à la suppression du ticket, car `support_ticket_id` passe alors à `NULL` ;
- **moteur** : moteur utilisé (`provider`, `model`) et moteur tenté (`attempted_provider`, `attempted_model`) ;
- **fallback** : cause normalisée dans `fallback_reason` (`key_missing`, `key_rejected`, `quota_exceeded`, `timeout`, `connection_error`, `http_error`, `invalid_response`, `other`), `NULL` si l'IA a répondu ;
- **tokens** : entrée, sortie, total, cache, raisonnement, et le bloc `usage` brut renvoyé par l'API (`usage_raw`) ;
- **durée** : `latency_ms`, durée réelle, y compris en cas d'échec ;
- **coût** : `estimated_cost`, en dollars, figé au moment de l'appel d'après `ai_pricing`. Pour chiffrer après coup les appels d'un modèle dont le tarif vient d'être renseigné : `php artisan zeno:estimate-ai-costs`.

Ces données permettent de suivre la consommation par jour, par équipe et par utilisateur, le coût, le taux de fallback et ses causes.

**Rétention** : la réponse brute du moteur (`raw_response`) et le message d'erreur (`error`) reprennent le texte de la demande. Ils sont effacés au-delà de 90 jours (`ZENO_AI_LOG_CONTENT_RETENTION_DAYS`) par `zeno:prune-ai-log-content`, chaque nuit. Ils sont aussi effacés immédiatement quand le ticket est supprimé. Les compteurs (tokens, coût, durée, cause) sont conservés.

Cette purge ne concerne que les logs IA. Le texte saisi reste sur le ticket (`support_tickets`).

---

## Mode simulation GLPI

Avec `GLPI_DRY_RUN=true`, Zeno n'écrit rien dans GLPI : les créations de tickets, les pièces jointes, les suivis et les solutions sont journalisés, et les tickets reçoivent un faux numéro à partir de **900000**. Les lectures (statistiques, export) restent réelles. Un badge « Mode test » s'affiche dans la barre de navigation.

Le mode simulation est **ignoré en production** (`APP_ENV=production`), et `scripts/deploy.sh` refuse de déployer avec `GLPI_DRY_RUN=true`.

---

## Stack technique

| Couche | Technologie |
|---|---|
| Framework | Laravel 12 (PHP 8.2+) |
| Base de données | PostgreSQL 17 |
| IA | OpenAI `gpt-5.4-mini` par défaut ; Anthropic Claude ou serveur local en option |
| Frontend | Blade, Alpine.js et Tailwind CSS, compilés par Vite |
| Auth | Laravel Breeze (email et mot de passe) |
| File d'attente, cache, sessions | Base de données (pas de Redis) |
| Hébergement cible | VPS Debian 12, nginx et php-fpm |

---

## Installation (développement)

Prérequis : PHP 8.2+ avec `pdo_pgsql`, Composer, Node.js et npm, PostgreSQL 17.

```bash
git clone https://github.com/Alookin/supportia.git zeno && cd zeno

composer install
npm install && npm run build          # le manifest Vite est requis par l'app ET par les tests

cp .env.example .env
php artisan key:generate
```

Dans `.env`, remplacer `DB_CONNECTION=sqlite` par PostgreSQL. Renseigner aussi la clé du moteur d'IA, et passer en simulation GLPI pour ne pas écrire dans le vrai GLPI :

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=zeno
DB_USERNAME=zeno
DB_PASSWORD=

OPENAI_API_KEY=
GLPI_DRY_RUN=true
```

```bash
createdb -O zeno zeno                 # rôle zeno déjà créé dans PostgreSQL
php artisan migrate
php artisan db:seed --class=CategorySeeder    # organisation via-mobilis et ses catégories (jetons GLPI fictifs)
php artisan db:seed --class=DemoUsersSeeder   # comptes de démo @demo.zeno.test, refusé en production
php artisan zeno:user-create vous@exemple.fr --name="Votre nom" --role=admin

php artisan serve
```

`CategorySeeder` crée l'organisation avec des jetons GLPI fictifs (`PLACEHOLDER`). Pour parler à un vrai GLPI, il faut renseigner `glpi_api_url`, `glpi_app_token` et `glpi_user_token` sur l'organisation, par exemple avec `php artisan tinker`. Il n'y a pas encore d'écran d'administration pour le faire.

Les comptes de démo utilisent le mot de passe défini par `DEMO_PASSWORD`, ou la valeur par défaut écrite dans `DemoUsersSeeder`.

Les tâches planifiées (file d'attente GLPI, synchronisations, purges) tournent avec `php artisan schedule:work` en local. En production, il faut une entrée cron : `* * * * * php artisan schedule:run`.

### Tests

```bash
npm run build        # obligatoire avant : sans manifest Vite, les tests qui rendent une page échouent
php artisan test
```

Les tests tournent sur SQLite en mémoire, imposé par `phpunit.xml`, avec des appels HTTP simulés. Ils n'appellent ni OpenAI ni GLPI.

Si Xdebug est installé, préfixer par `XDEBUG_MODE=off`, sinon PHP attend un débogueur.

---

## Configuration

### Variables d'environnement propres à Zeno

Les variables Laravel standard (`APP_*`, `DB_*`, `MAIL_*`, `SESSION_*`, `LOG_*`…) sont décrites dans `.env.example` et `.env.production.example`.

| Variable | Défaut | Rôle |
|---|---|---|
| `AI_PROVIDER` | `openai` | Moteur d'IA : `openai`, `claude` ou `local` (jamais en production) |
| `OPENAI_API_KEY` | (vide) | Clé OpenAI, obligatoire avec `openai` |
| `OPENAI_MODEL` | `gpt-5.4-mini` | Modèle OpenAI |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | URL de l'API OpenAI |
| `OPENAI_TIMEOUT` | `10` | Timeout en secondes, moteur `openai` |
| `CLAUDE_API_KEY` | (vide) | Clé Anthropic, avec `claude`. Une organisation peut avoir sa propre clé en base |
| `CLAUDE_MODEL` | `claude-sonnet-4-20250514` | Modèle Anthropic |
| `CLAUDE_VERIFY_SSL` | `true` | `false` uniquement en local, si le poste casse le SSL |
| `LOCAL_AI_BASE_URL` | `http://127.0.0.1:11434/v1` | Serveur local compatible OpenAI |
| `LOCAL_AI_MODEL` | (vide) | Modèle du serveur local |
| `LOCAL_AI_API_KEY` | (vide) | Clé du serveur local, si nécessaire |
| `SUPPORTIA_AI_TIMEOUT` | `25` | Timeout en secondes, moteurs `claude` et `local` uniquement |
| `SUPPORTIA_CONFIDENCE_THRESHOLD` | `0.7` | En dessous, l'utilisateur valide la proposition avant l'envoi |
| `SUPPORTIA_GLPI_TIMEOUT` | `15` | Timeout des appels GLPI, en secondes |
| `SUPPORTIA_ATTACHMENT_MAX_KB` | `10240` | Taille max d'une pièce jointe, en Ko (création et réponses). Effective seulement si `upload_max_filesize`, `post_max_size` et `client_max_body_size` la laissent passer (voir [Déploiement](#déploiement)) |
| `GLPI_VERIFY_SSL` | `true` | `false` pour un GLPI de test en certificat auto-signé |
| `GLPI_DRY_RUN` | `false` | Mode simulation GLPI, ignoré en production |
| `ZENO_NOTIFY_RESOLVED` | `true` | Email au demandeur quand GLPI résout son ticket |
| `ZENO_ESTIMATE_MAX_HOURS` | `120` | Au-delà, le délai habituel n'est pas affiché |
| `ZENO_AI_LOG_CONTENT_RETENTION_DAYS` | `90` | Conservation du contenu des logs IA, en jours |
| `DEMO_PASSWORD` | (voir le seeder) | Mot de passe des comptes de démo |

L'URL et les jetons GLPI ne sont pas dans `.env` : ils sont configurés **par organisation**, en base, et chiffrés.

---

## Commandes artisan

| Commande | Rôle | Planification |
|---|---|---|
| `zeno:user-create {email} [--name=] [--team=] [--role=member] [--glpi-user-id=] [--password=] [--org=via-mobilis]` | Créer un compte (mot de passe généré si absent) | |
| `zeno:user-update {email} [--team=] [--role=member\|team_admin\|admin]` | Changer l'équipe ou le rôle (`--team=none` pour retirer l'équipe) | |
| `zeno:category-team {slugs*} --team= [--detach] [--list] [--org=]` | Rattacher des catégories à une équipe, les en détacher, ou lister les rattachements | |
| `zeno:prune-drafts [--hours=24]` | Supprimer les propositions jamais validées, avec leurs fichiers | chaque nuit, 02:41 |
| `zeno:prune-ai-log-content [--days=]` | Effacer le contenu des logs IA au-delà de la rétention | chaque nuit, 02:51 |
| `zeno:estimate-ai-costs [--all]` | Chiffrer les appels IA d'après les tarifs configurés | |
| `glpi:sync-ticket-statuses [--limit=300]` | Synchroniser le statut et la catégorie des tickets ouverts, envoyer l'email « résolu » | toutes les 10 min |
| `glpi:sync-resolution-stats [--org=] [--months=12]` | Calculer le délai de résolution médian par catégorie | chaque nuit, 03:17 |
| `glpi:export-tickets [--org=] [--batch-size=1000] [--dir=glpi-export] [--fresh]` | Exporter les tickets GLPI avec suivis, solutions et tâches, en JSON Lines (avec reprise) | |
| `queue:work --stop-when-empty` | Traiter la file d'attente (envois GLPI en reprise) | chaque minute |

Seeders : `CategorySeeder` (organisation et catégories Via-Mobilis) et `DemoUsersSeeder` (comptes de démo, local uniquement).

---

## Architecture

```
Utilisateur ─ POST /support/tickets
   │
   ▼
SupportTicketController
   ├─ AIClassifierService ── OpenAI (ou Claude / local)
   │        │                 └─ échec → fallback mots-clés
   │        └─ ai_request_logs (tokens, coût, durée, cause du fallback)
   │
   ├─ confiance < seuil ──► statut needs_review : l'utilisateur valide, corrige ou annule
   │                         (POST …/confirm, DELETE …/draft)
   │
   └─ confiance ≥ seuil ou validation
            │
            ▼
      GlpiTicketPublisher ── GlpiClientService ── API REST GLPI
            │                                      (initSession, Ticket, Document)
            ├─ succès ──► statut created, numéro GLPI renvoyé
            └─ échec  ──► statut queued ── job CreateGlpiTicket (5 tentatives) ──► created ou failed
```

Statuts d'un ticket : `needs_review`, `queued`, `created`, `failed`, `resolved`, `closed`.

| Composant | Rôle |
|---|---|
| `AIClassifierService` | Contrôle de la saisie, prompt, appel du moteur, lecture du JSON, fallback mots-clés, journalisation |
| `AiPricing` | Coût estimé d'un appel d'après `ai_pricing` |
| `GlpiClientService` | Session GLPI, tickets, documents, suivis, solutions, statuts, mode simulation |
| `GlpiTicketPublisher` | Envoi d'un ticket et de ses pièces jointes vers GLPI, mise en file d'attente en cas d'échec |
| `CreateGlpiTicket` | Job de reprise des envois GLPI |
| `SupportTicketController` | Création, validation et annulation d'une proposition |
| `SupportDashboardController` | Détail, listes, tableau de bord, réponses, clôture, téléchargement des pièces jointes |
| `Organization`, `Team`, `User` | Organisation (config GLPI chiffrée), équipes, utilisateurs et rôles |
| `SupportTicket`, `TicketComment`, `TicketAttachment` | Ticket local, réponses et fichiers |
| `GlpiCategoryMap` | Catégories GLPI de l'organisation (slug pour l'IA, libellé simplifié, mots-clés, équipes, délai médian) |
| `AiRequestLog` | Journal des appels au moteur d'IA |

---

## Sécurité

- **Secrets** : les clés d'API sont dans `.env` et ne sont jamais envoyées au navigateur. Les jetons GLPI et la clé Claude propre à une organisation sont chiffrés en base (cast `encrypted`, clé `APP_KEY`).
- **Accès** : toutes les pages de l'application exigent une connexion et une organisation active. Seules la connexion, la réinitialisation du mot de passe et `/up` sont publiques. La visibilité d'un ticket dépend du rôle (voir [Rôles et équipes](#rôles-et-équipes)). La création et la validation de tickets sont limitées à 20 requêtes par minute.
- **Pièces jointes** : elles sont stockées hors de `public/`, contrôlées à la fois sur l'extension du nom et sur le type MIME détecté dans le contenu (un script renommé en `.txt` est refusé), et servies uniquement aux utilisateurs qui voient le ticket. Chaque téléchargement est journalisé. Les fichiers sont supprimés avec le ticket, quand une proposition est annulée ou purgée.
- **Données envoyées à un tiers** : le fournisseur du moteur d'IA (OpenAI par défaut) reçoit le texte de la demande et la liste des catégories. Les pièces jointes ne lui sont pas transmises.
- **Production** : `scripts/deploy.sh` refuse `APP_DEBUG=true`, `GLPI_DRY_RUN=true`, `AI_PROVIDER=local`, une valeur d'`AI_PROVIDER` autre que `openai`, `claude` ou `local` (y compris vide), et une `OPENAI_API_KEY` vide.
- `SECURITY_AUDIT.md` : audit des secrets dans l'historique git (mai 2026).

---

## Déploiement

La cible est un serveur Debian 12 avec nginx et php-fpm.

- `.env.production.example` : modèle de `.env` de production, sans aucun secret. À copier en `.env`, puis à remplir.
- `scripts/deploy.sh` : déploiement et mise à jour, idempotent. La procédure de première installation est en tête du script, avec les contrôles qu'il applique avant de déployer.

Après le premier déploiement, ajouter l'entrée cron `* * * * * php artisan schedule:run` et **sauvegarder `APP_KEY`**, car elle déchiffre les jetons GLPI stockés en base.

Réglages serveur nécessaires pour les pièces jointes de 10 Mo (`SUPPORTIA_ATTACHMENT_MAX_KB=10240`). `deploy.sh` ne les vérifie pas :

| Où | Réglage | Pourquoi |
|---|---|---|
| php-fpm (`php.ini`) | `upload_max_filesize = 10M` | Sinon PHP rejette le fichier : « Le fichier n'a pas pu être reçu » |
| php-fpm (`php.ini`) | `post_max_size = 52M` | Une création peut contenir 5 fichiers de 10 Mo. Sinon : erreur 413 |
| nginx | `client_max_body_size 52m;` | Le défaut est 1 Mo : au-delà, nginx répond 413 avant PHP |

Pour une autre limite, ajuster les trois valeurs : la limite PHP par fichier égale à la limite de Zeno, et le corps de requête au moins égal à 5 fois cette limite plus une marge.

---

## Roadmap

### En cours : pilote Via-Mobilis
Mise en service auprès des équipes commerciale et marketing de Via-Mobilis, sur le GLPI de production. L'objectif est de mesurer le taux de fallback, le coût réel, la justesse de la classification et l'adoption.

### À venir (rien de ce qui suit n'existe encore dans le code)

1. **Suggestions de solutions alimentées par l'historique GLPI (RAG)** : conçu dans les notes de projet (hors dépôt), non implémenté. Le principe : à la création d'un ticket, retrouver les tickets résolus similaires et leurs solutions pour aider l'utilisateur et le technicien. Seule brique déjà présente : l'export de l'historique (`glpi:export-tickets`).
2. **Version SaaS multi-tenant** : onboarding self-service des organisations, administration de la configuration GLPI et des catégories dans l'interface, branding par organisation, facturation à la consommation, connecteurs vers d'autres outils ITSM.

---

## Contribution

Code en anglais, interface en français, commits en [Conventional Commits](https://www.conventionalcommits.org/). Une branche par sujet, une pull request vers `main`, et les tests au vert (`npm run build`, puis `php artisan test`).
