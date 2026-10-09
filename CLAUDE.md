# Zeno

## Projet

Zeno (dépôt `supportia`, nom historique du projet) est une application web qui permet à des utilisateurs non techniques (commerciaux, marketing, services généraux…) de créer des tickets GLPI en langage naturel. L'IA (API OpenAI, `gpt-5.4-mini` par défaut) classe la demande, propose une catégorie et une priorité, structure la description, puis Zeno crée le ticket dans GLPI par son API REST.

Le modèle de données est multi-organisation : chaque organisation a sa propre instance GLPI (URL et jetons chiffrés en base), ses catégories, ses équipes et ses utilisateurs. En pratique, une seule organisation est en service : le pilote Via-Mobilis. Il n'existe ni onboarding ni écran d'administration des organisations.

`README.md` est la documentation de référence (fonctionnalités, variables, commandes, déploiement). Ce fichier résume ce qu'un agent doit savoir pour travailler dans le dépôt.

## Stack technique

- **Framework** : Laravel 12 (PHP 8.2+)
- **Base de données** : PostgreSQL 17. Les tests tournent sur SQLite en mémoire (`phpunit.xml`)
- **IA** : API OpenAI (`gpt-5.4-mini`, `/v1/chat/completions`) par défaut. Alternatives : `claude` (API Anthropic) et `local` (serveur auto-hébergé compatible OpenAI, interdit en production). Fallback par mots-clés si le moteur échoue
- **Frontend** : Blade, Alpine.js et Tailwind CSS, compilés par Vite (`npm run build`). Pas de SPA
- **Auth** : Laravel Breeze (email et mot de passe)
- **File d'attente, cache, sessions** : base de données (pas de Redis)
- **Hébergement cible** : serveur Debian 12 (nginx et php-fpm), déploiement par `scripts/deploy.sh`

## Architecture

```
Utilisateur → formulaire Blade (description, clients, pièces jointes)
    → POST /support/tickets (SupportTicketController::store)
    → AIClassifierService (OpenAI → JSON structuré ; échec → fallback mots-clés ; ligne dans ai_request_logs)
    → confiance < seuil (0,7) : statut needs_review, l'utilisateur valide (POST …/confirm) ou annule (DELETE …/draft)
    → GlpiTicketPublisher → GlpiClientService (API REST GLPI : initSession, Ticket, Document)
    → succès : statut created et numéro GLPI ; échec : statut queued, puis job CreateGlpiTicket
```

Toutes les routes applicatives sont des routes web sous `/support`, avec les middlewares `auth` et `org.active`. Il n'y a pas d'API REST publique : `routes/api.php` ne contient que la route Sanctum par défaut, `/api/user`.

Le ticket local (`support_tickets`) est enregistré avant l'envoi à GLPI. Si GLPI échoue, le job `CreateGlpiTicket` reprend l'envoi : 5 tentatives, backoff de 60 s à 1 h, puis statut `failed`. Il est exécuté par `queue:work`, planifié chaque minute (`routes/console.php`). En production, il faut donc le cron `* * * * * php artisan schedule:run`.

Statuts d'un ticket (`App\Enums\TicketStatus`) : `needs_review`, `queued`, `created`, `failed`, `resolved`, `closed`.

Rôles (`App\Enums\UserRole`) :
- `member` : voit ses tickets ;
- `team_admin` : voit ceux de son équipe et le tableau de bord de l'équipe ;
- `admin` : voit toute l'organisation.

Visibilité : `SupportTicket::scopeVisibleTo()` et `SupportTicketPolicy`.

## Structure des fichiers métier

```
app/
├── Console/Commands/          # zeno:* et glpi:* (voir Commandes utiles)
├── Enums/
│   ├── TicketStatus.php
│   └── UserRole.php
├── Http/Controllers/
│   ├── Api/SupportTicketController.php   # création, validation, annulation d'une proposition (routes web /support/tickets)
│   └── SupportDashboardController.php    # détail, listes, tableau de bord, réponses, clôture, pièces jointes
├── Http/Middleware/EnsureOrganizationActive.php   # alias org.active
├── Jobs/CreateGlpiTicket.php  # reprise des envois GLPI en échec
├── Models/
│   ├── Organization.php       # organisation : config GLPI chiffrée, branding de la page de connexion
│   ├── Team.php               # équipe (Commercial, Marketing…)
│   ├── User.php               # rôle, équipe, glpi_user_id
│   ├── SupportTicket.php      # ticket local (statut, classification, numéro GLPI)
│   ├── TicketComment.php      # réponses de l'utilisateur
│   ├── TicketAttachment.php   # pièces jointes + règles d'upload (source : config supportia.attachments)
│   ├── GlpiCategoryMap.php    # catégorie GLPI ↔ slug IA, libellé simplifié, mots-clés, équipes, délai médian
│   └── AiRequestLog.php       # journal des appels IA (tokens, coût, durée, cause du fallback)
├── Notifications/TicketResolvedNotification.php
├── Policies/SupportTicketPolicy.php
└── Services/
    ├── AIClassifierService.php   # contrôle de la saisie, prompt, OpenAI / Claude / local, fallback mots-clés
    ├── AiPricing.php             # coût estimé d'un appel (config supportia.ai_pricing)
    ├── GlpiClientService.php     # client API REST GLPI, mode simulation (GLPI_DRY_RUN)
    └── GlpiTicketPublisher.php   # envoi d'un ticket et de ses pièces jointes, mise en file d'attente
```

Configuration métier : `config/supportia.php`.

## Conventions

- Nom du produit : « Zeno » partout où un humain lit (texte, interface, documentation, commentaires). « supportia » est gelé comme identifiant technique existant (dépôt, `config/supportia.php`, variables `SUPPORTIA_*`) : pas de renommage pendant le pilote. Nouveaux identifiants en `ZENO_*` / `zeno:`
- Langue du code : anglais (noms de classes, méthodes, variables)
- Langue du contenu et de l'interface : français
- Dates stockées en UTC (`app.timezone`), affichées en Europe/Paris dans les vues
- Les clés d'API sont dans `.env`, jamais en dur. Seule exception : la clé Claude propre à une organisation, chiffrée en base
- Les catégories GLPI sont en base (`glpi_category_maps`), pas dans le code. Chaque organisation a son propre jeu, et une catégorie peut être réservée à des équipes
- Limites des pièces jointes (taille, extensions, types MIME) : uniquement dans `config/supportia.php` (`attachments`), lues par `TicketAttachment::rules()`

## Commandes utiles

```bash
php artisan migrate
php artisan db:seed --class=CategorySeeder      # organisation via-mobilis et ses catégories (jetons GLPI fictifs)
php artisan db:seed --class=DemoUsersSeeder     # comptes de démo @demo.zeno.test (refusé en production)

php artisan zeno:user-create {email} [--name= --team= --role= --glpi-user-id= --password= --org=]
php artisan zeno:user-update {email} [--team= --role=]        # --team=none retire l'équipe
php artisan zeno:category-team {slugs*} --team= [--detach] [--list]
php artisan zeno:prune-drafts [--hours=24]                      # planifiée 02:41
php artisan zeno:prune-ai-log-content [--days=]                 # planifiée 02:51
php artisan zeno:estimate-ai-costs [--all]
php artisan glpi:sync-ticket-statuses [--limit=300]             # planifiée toutes les 10 min
php artisan glpi:sync-resolution-stats [--org=] [--months=12]   # planifiée 03:17
php artisan glpi:export-tickets [--org=] [--batch-size=1000] [--dir=] [--fresh]
php artisan queue:work --stop-when-empty                        # planifiée chaque minute (reprise GLPI)

npm run build && php artisan test               # le build Vite doit précéder les tests
```

## Variables d'environnement

Indispensables :

```
AI_PROVIDER=openai                 # défaut ; claude ou local possibles (local interdit en production). Toute autre valeur, y compris vide : repli sur claude + warning, et deploy.sh refuse
OPENAI_API_KEY=                    # obligatoire avec openai (deploy.sh refuse une clé vide)
OPENAI_MODEL=gpt-5.4-mini
OPENAI_TIMEOUT=10                  # secondes, moteur openai uniquement
SUPPORTIA_CONFIDENCE_THRESHOLD=0.7
SUPPORTIA_AI_TIMEOUT=25            # secondes, moteurs claude et local uniquement
GLPI_DRY_RUN=false                 # true en local : aucune écriture GLPI, numéros ≥ 900000 ; ignoré en production
```

Avec `AI_PROVIDER=claude` : `CLAUDE_API_KEY` et `CLAUDE_MODEL` en plus.

La liste complète, avec les défauts, est dans le README (section Configuration) et dans `.env.example`. L'URL et les jetons GLPI sont par organisation, en base, pas dans `.env`.

## Points d'attention

- **Session GLPI** : un `initSession` ouvre la session. Le jeton est mis en cache 30 min et renouvelé automatiquement après un 401 (`GlpiClientService::getSessionToken`)
- **Champ `content` de GLPI** : il attend du HTML. Le Markdown produit par l'IA est converti (`markdownToGlpiHtml`)
- **Catégories GLPI** : identifiées par `itilcategories_id` (entier)
- **Priorité** : elle va de 1 (très basse) à 6 (majeure) dans GLPI. L'IA produit 1 à 5 (bornée dans `AIClassifierService`)
- **OpenAI** : les modèles GPT-5.x refusent `max_tokens`, il faut `max_completion_tokens`
- **Échec du moteur** : quel que soit le moteur, tout échec (HTTP, timeout, JSON illisible, clé absente) bascule sur le fallback mots-clés, sans erreur affichée à l'utilisateur. La confiance du fallback (0,35 au plus) le fait passer en validation, avec le seuil par défaut
- **Incidents OpenAI** : clé absente, refusée (401) ou quota épuisé (429) sont journalisés au niveau `error`, et `ai_request_logs.error` est préfixé `[OPENAI_KEY_MISSING]`, `[OPENAI_KEY_REJECTED]` ou `[OPENAI_QUOTA_EXCEEDED]`. `fallback_reason` contient la cause normalisée
- **Rétention** : le contenu des logs IA (`raw_response`, `error`) est purgé au-delà de 90 jours, et immédiatement à la suppression du ticket. Les compteurs sont conservés
- **Pièces jointes** : la limite de Zeno (10 Mo) ne vaut que si PHP et nginx laissent passer : `upload_max_filesize`, `post_max_size` (5 fichiers), `client_max_body_size`
- **Tests et scripts locaux** : `XDEBUG_MODE=off` (sinon PHP attend le débogueur sur le port 9003) et `php8.2` (seule version avec `pdo_pgsql`)

## Roadmap

Ce qui suit n'existe pas encore dans le code.

### En cours : pilote Via-Mobilis
Mise en service auprès des équipes commerciale et marketing, sur le GLPI de production. L'objectif est de mesurer le taux de fallback, le coût, la justesse de la classification et l'adoption.

### Ensuite : suggestions de solutions à partir de l'historique GLPI (RAG)
Conçu dans les notes de projet, hors dépôt, et non implémenté. Le principe : retrouver les tickets résolus similaires et leurs solutions. Seule brique présente : l'export de l'historique (`glpi:export-tickets`).

### Puis : version SaaS multi-tenant
- Onboarding self-service des organisations
- Administration du GLPI, des catégories et des utilisateurs dans l'interface
- Branding par organisation
- Facturation à la consommation
- Connecteurs vers d'autres outils ITSM (Redmine, Jira Service Management)

### Plus tard : internationalisation
- FR / EN / IT avec le système i18n de Laravel (`lang/`)
- Détection de la langue du navigateur (`Accept-Language`)
- Langue forcée par organisation ou par utilisateur
- Aujourd'hui, les textes des vues sont écrits en français en dur, et seuls les messages Laravel sont traduits (`lang/fr/`)
