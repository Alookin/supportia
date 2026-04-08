# SupportIA

## Projet

SupportIA est une application SaaS standalone qui permet à des utilisateurs non-techniques (commerciaux, services généraux, etc.) de créer des tickets GLPI en langage naturel. L'IA (Claude API) classifie automatiquement la demande, suggère une catégorie et une priorité, structure la description, puis crée le ticket dans GLPI via son API REST.

L'application est multi-tenant : chaque organisation configure sa propre instance GLPI, ses catégories, et ses utilisateurs.

## Stack technique

- **Framework** : Laravel 11+ (PHP 8.2+)
- **Base de données** : PostgreSQL 17
- **IA** : Claude API (Sonnet) via HTTP, fallback par mots-clés si indisponible
- **Frontend** : Blade + Alpine.js + Tailwind CSS (pas de SPA, pas de build JS complexe)
- **Auth** : Laravel Breeze (simple email/password)
- **Hébergement cible** : VPS Hetzner (Debian 12), déploiement via git pull + artisan

## Architecture

```
Commercial → Formulaire Blade (textarea + client)
    → POST /api/tickets
    → AIClassifierService (Claude API → JSON structuré)
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
│   ├── AIClassifierService.php # Appel Claude API + fallback mots-clés
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
CLAUDE_API_KEY=sk-ant-...
CLAUDE_MODEL=claude-sonnet-4-20250514
SUPPORTIA_CONFIDENCE_THRESHOLD=0.7
SUPPORTIA_AI_TIMEOUT=5
```

Les variables GLPI sont par organisation (en base), pas dans .env.

## Points d'attention

- L'API GLPI nécessite un initSession avant chaque série d'appels
- Le session_token GLPI expire → gérer le refresh
- Le champ `content` de GLPI attend du texte/HTML, pas du Markdown
- Les catégories GLPI sont identifiées par `itilcategories_id` (entier)
- La priorité GLPI va de 1 (très basse) à 6 (majeure), nous utilisons 1-5

## Roadmap

### V1 — MVP (en cours)
- Formulaire commercial en langage naturel
- Classification IA (Claude API) + fallback mots-clés
- Création automatique de tickets GLPI via API REST
- Dashboard de suivi des tickets
- 9 catégories simplifiées pour les commerciaux, 26 au total pour l'IA
- Mode review quand la confiance IA est basse
- Retry automatique si GLPI est indisponible

### V2 — Base de connaissances
- Intégration de la base de connaissances GLPI (KnowbaseItem)
- À la création d'un ticket, recherche automatique des articles pertinents via l'API GLPI (GET /KnowbaseItem) par mots-clés et catégorie
- Analyse sémantique par Claude pour trouver l'article le plus pertinent (pas juste par catégorie mais par similarité avec la description)
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
