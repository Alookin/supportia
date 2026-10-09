#!/usr/bin/env bash
#
# Zeno — déploiement / mise à jour en production (Debian 12, nginx + php-fpm).
#
# À lancer depuis la racine du déploiement, avec l'utilisateur propriétaire du dossier :
#   ./scripts/deploy.sh
#
# Première installation (une seule fois, avant le premier appel) :
#   git clone https://github.com/Alookin/supportia.git /var/www/zeno && cd /var/www/zeno
#   cp .env.production.example .env && $EDITOR .env     # base, SMTP, clé d'API
#   php artisan key:generate                            # puis sauvegarder APP_KEY
#   ./scripts/deploy.sh
#
# Le script est idempotent : il peut être relancé après chaque mise à jour.
# Il ne touche ni au .env, ni à la configuration système, ni à la base (hors migrations).

set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
fail() { printf '\n\033[31mÉCHEC : %s\033[0m\n' "$1" >&2; exit 1; }

# ─── Vérifications préalables ────────────────────────────────────────────────
say "Vérifications"

[ -f .env ] || fail ".env absent. Copier .env.production.example et le remplir."

grep -q '^APP_ENV=production' .env || fail "APP_ENV doit valoir production dans .env."
grep -q '^APP_DEBUG=false'     .env || fail "APP_DEBUG doit valoir false en production."
grep -q '^APP_KEY=.\+'         .env || fail "APP_KEY vide. Lancer : $PHP_BIN artisan key:generate"

if grep -q '^GLPI_DRY_RUN=true' .env; then
    fail "GLPI_DRY_RUN=true : aucun ticket ne serait créé dans GLPI."
fi
# Valeur d'une variable du .env (dernière occurrence, guillemets et espaces retirés)
env_value() { { grep "^$1=" .env || true; } | tail -n 1 | cut -d= -f2- | sed -e 's/[[:space:]]*$//' -e 's/^["'\'']//' -e 's/["'\'']$//'; }

# Absente : défaut openai, comme config/supportia.php. Présente mais vide : Laravel lit "" et,
# comme pour toute valeur inconnue, AIClassifierService retombe sur Claude (sans clé : fallback permanent).
if grep -q '^AI_PROVIDER=' .env; then
    AI_PROVIDER_VALUE="$(env_value AI_PROVIDER)"
else
    AI_PROVIDER_VALUE="openai"
fi
case "$AI_PROVIDER_VALUE" in
    openai|claude|local) ;;
    *) fail "AI_PROVIDER=\"$AI_PROVIDER_VALUE\" n'est pas une valeur admise (openai, claude ou local, en minuscules) : Zeno retomberait silencieusement sur Claude." ;;
esac
if [ "$AI_PROVIDER_VALUE" = "local" ]; then
    fail "AI_PROVIDER=local interdit en production : moteur d'IA hors du périmètre Via-Mobilis, réservé aux démos."
fi
if [ "$AI_PROVIDER_VALUE" = "openai" ]; then
    case "$(env_value OPENAI_API_KEY)" in
        ''|'<'*) fail "OPENAI_API_KEY vide alors que le moteur d'IA est openai : la classification tomberait sur le fallback mots-clés." ;;
    esac
fi

command -v "$PHP_BIN" >/dev/null     || fail "php introuvable (PHP_BIN=$PHP_BIN)."
command -v "$COMPOSER_BIN" >/dev/null || fail "composer introuvable."
command -v npm >/dev/null            || fail "npm introuvable."

"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.2", ">=") ? 0 : 1);' \
    || fail "PHP 8.2 minimum requis ($("$PHP_BIN" -r 'echo PHP_VERSION;') installé)."

echo "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') · $(node --version) · dossier $APP_DIR"

# ─── Récupération du code ────────────────────────────────────────────────────
say "Récupération du code"
if [ -n "$(git status --porcelain)" ]; then
    fail "modifications locales non committées dans $APP_DIR. Les régler avant de déployer."
fi
git pull --ff-only

# ─── Mode maintenance ────────────────────────────────────────────────────────
say "Passage en maintenance"
"$PHP_BIN" artisan down --retry=30 || true
trap '"$PHP_BIN" artisan up >/dev/null 2>&1 || true' EXIT

# ─── Dépendances ─────────────────────────────────────────────────────────────
say "Dépendances PHP"
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --prefer-dist

say "Build front"
npm ci
npm run build

# ─── Base et liens ───────────────────────────────────────────────────────────
say "Migrations"
"$PHP_BIN" artisan migrate --force

if [ ! -e public/storage ]; then
    say "Lien storage"
    "$PHP_BIN" artisan storage:link
fi

# ─── Caches ──────────────────────────────────────────────────────────────────
say "Caches de production"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

# ─── Reprise ─────────────────────────────────────────────────────────────────
say "Redémarrage des workers et sortie de maintenance"
"$PHP_BIN" artisan queue:restart
"$PHP_BIN" artisan up
trap - EXIT

say "Contrôles finaux"
"$PHP_BIN" artisan about --only=environment
echo
echo "Vérifier ensuite :"
echo "  - $(grep '^APP_URL=' .env | cut -d= -f2-)/up  doit répondre 200"
echo "  - la ligne de cron : crontab -l | grep schedule:run"
echo "  - un ticket de bout en bout : création, pièce jointe, clôture"
