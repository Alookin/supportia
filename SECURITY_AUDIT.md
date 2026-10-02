# Security Audit — Secrets in git history

**Date** : 2026-05-05
**Scope** : full git history of `main` (14 commits, HEAD = `f9a8296`)
**Author** : Zeno hardening pre-pilot — Chantier 1

## Méthodologie

Recherche des patterns suivants sur l'intégralité de l'historique (`git log --all`) :

| Pattern | Cible | Commande |
|---|---|---|
| `sk-ant-` | Clé API Anthropic / Claude | `git log --all -p -S "sk-ant-"` |
| `App-Token` | Header / valeur App-Token GLPI | `git log --all -p -S "App-Token"` |
| `Session-Token` | Header / valeur Session-Token GLPI | `git log --all -p -S "Session-Token"` |
| `.env`, `.env.local`, `.env.production`, `.env.staging` | Fichiers d'environnement | `git log --all --full-history -- .env*` |
| `*.key`, `*.pem`, `auth.json` | Clés privées / credentials Composer | `git log --all --diff-filter=A -- '*.key' '*.pem' 'auth.json'` |
| Fichiers trackés actuellement | Index courant | `git ls-files \| grep -iE "\.env\|\.key\|\.pem"` |
| `sk-ant-[a-zA-Z0-9_-]{20,}` | Clé Anthropic réelle (≥ 20 chars utiles) | grep worktree |

## Findings

### `sk-ant-` — 3 occurrences, **toutes placeholders**

```
.env.example      : CLAUDE_API_KEY=sk-ant-...
.env.example      : CLAUDE_API_KEY=sk-ant-...        (duplicate au cours d'un commit)
README.md         : CLAUDE_API_KEY=sk-ant-YOUR_KEY_HERE
```

Aucune clé réelle. Le grep strict `sk-ant-[a-zA-Z0-9_-]{20,}` sur le worktree retourne 0 match.

### `App-Token` — 2 occurrences, **toutes légitimes**

```
app/Services/GlpiClientService.php : 'App-Token' => $organization->glpi_app_token,
app/Services/GlpiClientService.php : 'App-Token' => $organization->glpi_app_token,
```

Nom de header HTTP standard GLPI. Valeur lue depuis BDD (table `organizations`), jamais hardcodée.

### `Session-Token` — 1 occurrence, **légitime**

```
app/Services/GlpiClientService.php : 'Session-Token' => $sessionToken,
```

Nom de header HTTP standard GLPI. Valeur = variable locale obtenue via `initSession`.

### Fichiers d'environnement

| Fichier | Statut |
|---|---|
| `.env` | Jamais tracké |
| `.env.local` | Jamais tracké |
| `.env.production` | Jamais tracké |
| `.env.staging` | Jamais tracké |
| `.env.example` | Tracké (normal — ne contient que des placeholders) |

### Fichiers de clés privées / credentials

`*.key`, `*.pem`, `auth.json` : aucun fichier de ce type n'a jamais été ajouté à l'historique.

## Conclusion

**Aucun secret réel n'est présent dans l'historique git.** L'audit est **clean**.

Les seuls matches sont :
- des placeholders documentaires (`sk-ant-...`, `sk-ant-YOUR_KEY_HERE`)
- des noms de headers HTTP standards GLPI (`App-Token`, `Session-Token`) avec valeurs dynamiques

Aucune rotation de clé n'est nécessaire au titre de cet audit.

## Recommandations préventives (hors scope chantier 1)

À considérer avant les pilotes :

- **Pre-commit hook** (`gitleaks` ou `pre-commit-hooks/detect-private-key`) pour bloquer toute future fuite
- **`.gitignore` durci** : ajouter `.env.local`, `.env.*.local`, `*.pem`, `*.crt` (couvert dans le commit suivant de ce chantier)
- **CI scan** : intégrer `gitleaks` ou `trufflehog` au pipeline (à prévoir quand CI sera en place)
- **Rotation périodique** : documenter la procédure de rotation `CLAUDE_API_KEY` dans `RUNBOOK.md` (chantier 7)

## Reproduction

Pour rejouer l'audit :

```bash
git log --all -p -S "sk-ant-" | grep -E "^\+.*sk-ant-"
git log --all -p -S "App-Token" | grep -E "^\+.*App-Token"
git log --all -p -S "Session-Token" | grep -E "^\+.*Session-Token"
git log --all --full-history -- .env .env.local .env.production .env.staging
git log --all --diff-filter=A -- '*.key' '*.pem' 'auth.json'
git ls-files | grep -iE "\.env|\.key|\.pem"
```
