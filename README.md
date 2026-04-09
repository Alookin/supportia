# Zeno — Portail de support assisté par IA

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-17-336791?style=flat-square&logo=postgresql&logoColor=white)
![Claude API](https://img.shields.io/badge/Claude_API-Anthropic-D97757?style=flat-square)
![License](https://img.shields.io/badge/License-MIT-22c55e?style=flat-square)

Zeno permet à des utilisateurs non-techniques (commerciaux, services généraux…) de créer des tickets GLPI en langage naturel. L'IA (Claude API) classifie automatiquement la demande, suggère une catégorie et une priorité, structure la description, puis crée le ticket dans GLPI via son API REST.

L'application est **multi-tenant** : chaque organisation configure sa propre instance GLPI, ses catégories, ses utilisateurs et son branding (logo, couleur).

---

## Pourquoi Zeno ?

Dans un contexte de support terrain avec 15 commerciaux itinérants, **93 % des tickets remontés ne contenaient ni catégorie, ni priorité, ni description structurée**. Les techniciens passaient un temps considérable à qualifier chaque demande avant de pouvoir la traiter.

Les outils de ticketing classiques (GLPI, Jira, Redmine) présentent une interface pensée pour des profils techniques, ce qui freine leur adoption par les équipes terrain. La saisie manuelle des catégories ITSM, des niveaux de priorité et des descriptions structurées est perçue comme une contrainte sans valeur ajoutée par les non-techniciens.

Zeno résout ce problème en **supprimant la friction** : le commercial décrit le problème comme il l'écrirait dans un message, et l'IA s'occupe du reste — qualification, structuration, création dans GLPI.

---

## Fonctionnalités

- **Formulaire en langage naturel** — le commercial décrit le problème en quelques phrases, sans connaître la nomenclature GLPI
- **Classification IA (Claude API)** — catégorie, priorité, titre structuré et description générés automatiquement ; fallback par mots-clés si l'API est indisponible
- **Mode review** — quand la confiance IA est basse, le commercial valide et ajuste avant envoi
- **Multi-clients par ticket** — possibilité d'associer plusieurs clients (ID + nom) à une même demande
- **Intégration GLPI via API REST** — création de ticket avec session token, retry automatique si GLPI est down
- **Capture d'écran** — pièce jointe optionnelle uploadée avec le ticket
- **Dashboard de suivi** — vue d'ensemble de l'activité par organisation
- **Page détail ticket** — historique, classification IA, description structurée, commentaires
- **Estimation du temps de traitement** — calculée sur les tickets précédents de la même catégorie
- **Architecture multi-tenant** — une seule instance, plusieurs organisations avec configuration GLPI et branding indépendants
- **Page de connexion brandée** — logo et couleur de l'organisation affichés sur la page de login

---

## Stack technique

| Couche | Technologie |
|--------|-------------|
| Framework | Laravel 12 (PHP 8.2+) |
| Base de données | PostgreSQL 17 |
| IA | Claude API — `claude-sonnet-4-20250514` (Anthropic) |
| Frontend | Blade + Alpine.js + Tailwind CSS |
| Auth | Laravel Breeze (email / mot de passe) |
| Hébergement cible | VPS Hetzner, Debian 12 |

---

## Installation

```bash
# 1. Cloner le dépôt
git clone <url-du-repo> zeno
cd zeno

# 2. Installer les dépendances PHP
composer install

# 3. Copier et configurer l'environnement
cp .env.example .env
php artisan key:generate

# 4. Créer la base de données PostgreSQL
createdb zeno
# puis renseigner DB_DATABASE, DB_USERNAME, DB_PASSWORD dans .env

# 5. Appliquer les migrations
php artisan migrate

# 6. (Optionnel) Charger les catégories de démo
php artisan db:seed --class=CategorySeeder

# 7. Lier le stockage public (captures d'écran, logos)
php artisan storage:link

# 8. Installer les assets frontend
npm install && npm run build

# 9. Lancer le serveur de développement
php artisan serve
```

---

## Configuration

### Variables `.env` requises

```env
# IA — Claude API (Anthropic)
CLAUDE_API_KEY=sk-ant-...
CLAUDE_MODEL=claude-sonnet-4-20250514

# Seuil de confiance IA (0.0–1.0) en dessous duquel le ticket passe en review
SUPPORTIA_CONFIDENCE_THRESHOLD=0.7

# Timeout des appels IA en secondes
SUPPORTIA_AI_TIMEOUT=5
```

> Les paramètres GLPI (URL API, tokens) sont configurés **par organisation** en base de données, pas dans `.env`.

### Branding organisation

Chaque organisation peut avoir un logo et une couleur principale stockés en base :

```php
Organization::find($id)->update([
    'logo_path'     => 'images/mon-logo.png', // relatif à public/
    'primary_color' => '#1a4fd6',
]);
```

---

## Architecture

### Flux de traitement d'un ticket

```
  Navigateur
  ┌─────────────────────────────────────────────────┐
  │  Commercial                                     │
  │  "Le client 24453 n'arrive plus à se connecter" │
  └───────────────────┬─────────────────────────────┘
                      │ POST /support/tickets
                      ▼
  ┌─────────────────────────────────────────────────┐
  │  Zeno (Laravel)                                 │
  │                                                 │
  │  SupportTicket créé en base (statut: pending)   │
  │                  │                              │
  │                  ▼                              │
  │  AIClassifierService                            │
  │  ┌─────────────────────────────┐               │
  │  │  Claude API (Anthropic)     │               │
  │  │  → catégorie, priorité      │               │
  │  │  → titre structuré          │               │
  │  │  → description technique    │               │
  │  │  → score de confiance       │               │
  │  └──────────────┬──────────────┘               │
  │                 │ confiance ≥ 0.7 ?             │
  │         OUI ◄───┴───► NON → mode review        │
  │          │                                      │
  │          ▼                                      │
  │  GlpiClientService                              │
  │  ┌─────────────────────────────┐               │
  │  │  API REST GLPI              │               │
  │  │  initSession → POST /Ticket │               │
  │  │  → ticket #XXXX créé        │               │
  │  └──────────────┬──────────────┘               │
  │                 │ GLPI down ? → retry (cron)    │
  │                 ▼                               │
  │  SupportTicket mis à jour (statut: created)     │
  └───────────────────┬─────────────────────────────┘
                      │ Réponse JSON
                      ▼
  ┌─────────────────────────────────────────────────┐
  │  Commercial voit : ticket #XXXX, estimation     │
  └─────────────────────────────────────────────────┘
```

### Composants principaux

| Fichier | Rôle |
|---------|------|
| `AIClassifierService` | Construit le prompt, appelle Claude API, parse le JSON, fallback mots-clés |
| `GlpiClientService` | Gestion session GLPI, création de ticket, conversion Markdown → HTML |
| `SupportTicketController` | Validation, orchestration IA → GLPI, réponse JSON |
| `RetryGlpiTicketCreation` | Job de retry pour les tickets non envoyés à GLPI |
| `Organization` | Tenant : config GLPI, clé Claude, branding, catégories |
| `SupportTicket` | Ticket local (audit, statut, retry, estimation) |
| `GlpiCategoryMap` | Mapping slug IA ↔ ID catégorie GLPI par organisation |

### Commandes artisan

```bash
php artisan support:retry-glpi              # Rejouer les tickets en échec GLPI
php artisan db:seed --class=CategorySeeder  # Catégories de démo
php artisan test                            # Suite de tests
```

---

## Sécurité

- **Tokens GLPI chiffrés en base** — `glpi_app_token` et `glpi_user_token` sont stockés avec le chiffrement symétrique de Laravel (`cast: 'encrypted'`) ; ils ne sont jamais lisibles en clair dans la base
- **Clé Claude API non exposée** — `CLAUDE_API_KEY` reste dans `.env` côté serveur ; aucune clé n'est transmise au navigateur
- **Authentification requise** — toutes les routes de l'application passent par le middleware `auth` de Laravel Breeze
- **Données hébergées on-premise** — l'instance est déployée sur VPS dédié (Hetzner) ; aucune donnée client ne transite par un service tiers en dehors de l'API Claude (description du ticket uniquement)
- **Fallback gracieux** — si l'API Claude est indisponible, Zeno bascule automatiquement sur une classification par mots-clés et passe le ticket en mode review ; le service ne s'interrompt pas

---

## Coûts

| Poste | Estimation |
|-------|-----------|
| Coût par ticket (Claude Sonnet) | ~0,003 € |
| Volume normal (équipe de 15) | < 10 €/mois |
| Migration GLPI | 0 € — GLPI reste en place, Zeno s'y connecte via API |
| Infrastructure | VPS Hetzner CAX11 (~4 €/mois) |

Le modèle `claude-sonnet-4-20250514` traite chaque description en moins de 3 secondes pour un coût marginal. À 100 tickets/jour, le coût IA reste inférieur à 10 €/mois.

---

## Roadmap

### V1 — MVP ✓
- Formulaire en langage naturel
- Classification IA + fallback mots-clés
- Intégration GLPI via API REST
- Multi-clients par ticket
- Dashboard, détail ticket, commentaires
- Capture d'écran
- Estimation du temps de traitement
- Page de login brandée par organisation
- Retry automatique si GLPI indisponible

### V2 — Base de connaissances & connecteurs
- Intégration de la base de connaissances GLPI (`KnowbaseItem`)
- Recherche automatique d'articles pertinents à la création d'un ticket
- Analyse sémantique par Claude pour suggérer la solution au technicien
- Génération d'articles KB depuis la résolution d'un ticket
- Connecteur Redmine

### V3 — Multi-tenant SaaS & sécurité
- Onboarding self-service pour de nouvelles organisations
- Intégration OIDC (SSO / authentification centralisée)
- Connecteurs vers d'autres ITSM (Jira Service Management…)
- Facturation à la consommation

### V4 — Internationalisation (i18n)
- Support multilingue FR / EN / IT via le système i18n de Laravel
- Détection automatique de la langue du navigateur (`Accept-Language`)
- Langue forcée par organisation ou par utilisateur

---

## Contribution

Les contributions sont les bienvenues. Pour proposer une modification :

1. Forker le dépôt
2. Créer une branche (`git checkout -b feature/ma-fonctionnalite`)
3. Commiter les changements (`git commit -m 'feat: description'`)
4. Ouvrir une Pull Request

Conventions : code en anglais, UI en français, commits en [Conventional Commits](https://www.conventionalcommits.org/).

---

## License

Ce projet est distribué sous licence [MIT](LICENSE).
