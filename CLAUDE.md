# SupportIA

## Projet

SupportIA est une application SaaS standalone qui permet à des utilisateurs non-techniques (commerciaux, services généraux, etc.) de créer des tickets GLPI en langage naturel. L'IA (API OpenAI, `gpt-5.4-mini` par défaut) classifie automatiquement la demande, suggère une catégorie et une priorité, structure la description, puis crée le ticket dans GLPI via son API REST.

L'application est multi-tenant : chaque organisation configure sa propre instance GLPI, ses catégories, et ses utilisateurs.

## Stack technique

- **Framework** : Laravel 11+ (PHP 8.2+)
- **Base de données** : PostgreSQL 17
- **IA** : API OpenAI (`gpt-5.4-mini`, `/v1/chat/completions`) via HTTP, par défaut. Alternatives : `claude` (API Anthropic) et `local` (serveur auto-hébergé compatible OpenAI, interdit en production). Fallback par mots-clés si le moteur échoue
- **Frontend** : Blade + Alpine.js + Tailwind CSS (pas de SPA, pas de build JS complexe)
- **Auth** : Laravel Breeze (simple email/password)
- **Hébergement cible** : VPS Hetzner (Debian 12), déploiement via git pull + artisan

## Architecture

```
Commercial → Formulaire Blade (textarea + client)
    → POST /api/tickets
    → AIClassifierService (OpenAI → JSON structuré, fallback mots-clés)
    → GlpiClientService (API REST GLPI → ticket créé)
    → Réponse avec numéro de ticket
```

Chaque requête est loguée localement (table `support_tickets`) avant d'être envoyée à GLPI. Si GLPI est down, un job Laravel retry la création.

## Structure des fichiers métier

```
app/
├── Models/
│   ├── Organization.php        # Tenant (entreprise cliente)
│   ├── SupportTicket.php       # Ticket local (audit + retry)
│   ├── GlpiCategoryMap.php     # Mapping catégorie GLPI ↔ slug IA
│   └── AiRequestLog.php        # Log des appels IA (debug/tuning)
├── Services/
│   ├── AIClassifierService.php # Appel OpenAI / Claude / local + fallback mots-clés
│   └── GlpiClientService.php   # Client API REST GLPI (session, tickets)
├── Http/Controllers/Api/
│   └── SupportTicketController.php
├── Jobs/
│   └── RetryGlpiTicketCreation.php
├── Console/Commands/
│   └── RetryGlpiTicketsCommand.php
```

## Conventions

- Langue du code : anglais (noms de classes, méthodes, variables)
- Langue du contenu/UI : français
- Toutes les dates en UTC, affichage en Europe/Paris
- Les clés API sont dans .env, jamais en dur
- Les catégories GLPI sont en base, pas dans le code
- Chaque organisation a son propre jeu de catégories

## Commandes utiles

```bash
php artisan migrate                          # Appliquer les migrations
php artisan db:seed --class=CategorySeeder   # Catégories de démo
php artisan support:retry-glpi               # Retry tickets en échec GLPI
php artisan test                             # Tests
```

## Variables d'environnement requises

```
AI_PROVIDER=openai                 # défaut ; claude ou local possibles (local interdit en production)
OPENAI_API_KEY=                    # obligatoire avec openai (deploy.sh refuse une clé vide)
OPENAI_MODEL=gpt-5.4-mini
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_TIMEOUT=10                  # secondes, moteur openai uniquement
SUPPORTIA_CONFIDENCE_THRESHOLD=0.7
SUPPORTIA_AI_TIMEOUT=25            # secondes, moteurs claude et local uniquement
```

Avec `AI_PROVIDER=claude` : `CLAUDE_API_KEY` et `CLAUDE_MODEL` en plus.

Les variables GLPI sont par organisation (en base), pas dans .env.

## Points d'attention

- L'API GLPI nécessite un initSession avant chaque série d'appels
- Le session_token GLPI expire → gérer le refresh
- Le champ `content` de GLPI attend du texte/HTML, pas du Markdown
- Les catégories GLPI sont identifiées par `itilcategories_id` (entier)
- La priorité GLPI va de 1 (très basse) à 6 (majeure), nous utilisons 1-5
- OpenAI : les modèles GPT-5.x refusent `max_tokens`, il faut `max_completion_tokens`
- Quel que soit le moteur, tout échec (HTTP, timeout, JSON illisible, clé absente) bascule sur le fallback mots-clés : jamais d'erreur affichée au commercial
- Clé OpenAI absente, refusée (401) ou quota épuisé (429) : log en `error` et `ai_request_logs.error` préfixé `[OPENAI_KEY_MISSING]`, `[OPENAI_KEY_REJECTED]` ou `[OPENAI_QUOTA_EXCEEDED]`
- Tests et scripts locaux : `XDEBUG_MODE=off` (sinon PHP attend le débogueur sur le port 9003) et `php8.2` (seule version avec `pdo_pgsql`)

## Roadmap

### V1 — MVP (en cours)
- Formulaire commercial en langage naturel
- Classification IA (OpenAI `gpt-5.4-mini`) + fallback mots-clés
- Création automatique de tickets GLPI via API REST
- Dashboard de suivi des tickets
- 9 catégories simplifiées pour les commerciaux, 26 au total pour l'IA
- Mode review quand la confiance IA est basse
- Retry automatique si GLPI est indisponible

### V2 — Base de connaissances
- Intégration de la base de connaissances GLPI (KnowbaseItem)
- À la création d'un ticket, recherche automatique des articles pertinents via l'API GLPI (GET /KnowbaseItem) par mots-clés et catégorie
- Analyse sémantique par l'IA pour trouver l'article le plus pertinent (pas juste par catégorie mais par similarité avec la description)
- Articles suggérés attachés au ticket → le technicien voit la solution immédiatement
- Génération automatique d'articles : quand un technicien résout un ticket, SupportIA propose de transformer la solution en article de base de connaissances

### V3 — Multi-tenant & SaaS
- Onboarding self-service pour de nouvelles organisations
- Chaque orga configure ses catégories, son GLPI, ses utilisateurs
- Connecteurs vers d'autres ITSM (Redmine, Jira Service Management)
- Facturation à la consommation

### V4 — Internationalisation (i18n)
- Support multilingue FR / EN / IT via le système i18n de Laravel (`resources/lang/`)
- Détection automatique de la langue du navigateur (`Accept-Language`) via un middleware dédié
- Toutes les chaînes UI passent par `__()` / `trans()`
- La langue peut être forcée par organisation (colonne `locale` sur `organizations`) ou par utilisateur
