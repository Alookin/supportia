#!/bin/bash
# ═══════════════════════════════════════════════════════════
# SupportIA — Script de bootstrap
# ═══════════════════════════════════════════════════════════
#
# Prérequis :
#   - PHP 8.2+, Composer, PostgreSQL, Node.js
#
# Usage :
#   chmod +x bootstrap.sh && ./bootstrap.sh
# ═══════════════════════════════════════════════════════════

set -e

echo "═══════════════════════════════════════"
echo "  SupportIA — Bootstrap"
echo "═══════════════════════════════════════"

# 1. Créer le projet Laravel
echo ""
echo "→ Création du projet Laravel..."
composer create-project laravel/laravel supportia
cd supportia

# 2. Installer Breeze (auth simple) + Sanctum (API tokens)
echo ""
echo "→ Installation de Laravel Breeze..."
composer require laravel/breeze --dev
php artisan breeze:install blade

# 3. Configurer PostgreSQL dans .env
echo ""
echo "→ Configuration .env..."
sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=pgsql/' .env
sed -i 's/# DB_HOST=127.0.0.1/DB_HOST=127.0.0.1/' .env
sed -i 's/# DB_PORT=5432/DB_PORT=5432/' .env
sed -i 's/# DB_DATABASE=laravel/DB_DATABASE=supportia/' .env
sed -i 's/# DB_USERNAME=root/DB_USERNAME=supportia/' .env
sed -i 's/# DB_PASSWORD=/DB_PASSWORD=your_password_here/' .env

# 4. Ajouter les variables SupportIA
cat >> .env << 'EOF'

# ═══ SupportIA ═══
CLAUDE_API_KEY=sk-ant-YOUR_KEY_HERE
CLAUDE_MODEL=claude-sonnet-4-20250514
SUPPORTIA_CONFIDENCE_THRESHOLD=0.7
SUPPORTIA_AI_TIMEOUT=5
EOF

# 5. Créer la base PostgreSQL
echo ""
echo "→ Création de la base de données..."
createdb supportia 2>/dev/null || echo "   Base 'supportia' existe peut-être déjà"

# 6. Build front
echo ""
echo "→ Installation des dépendances front..."
npm install
npm run build

echo ""
echo "═══════════════════════════════════════"
echo "  Bootstrap terminé !"
echo ""
echo "  Prochaines étapes avec Claude Code :"
echo ""
echo "  1. cd supportia"
echo "  2. Copier les fichiers SupportIA :"
echo "     - app/Models/*.php"
echo "     - app/Services/*.php"
echo "     - app/Http/Controllers/Api/*.php"
echo "     - app/Jobs/*.php"
echo "     - app/Console/Commands/*.php"
echo "     - config/supportia.php"
echo "     - database/migrations/2025_04_03_*"
echo "     - database/seeders/CategorySeeder.php"
echo "     - routes/supportia.php"
echo "     - resources/views/support/create.blade.php"
echo ""
echo "  3. Dans routes/api.php, ajouter :"
echo "     require __DIR__ . '/supportia.php';"
echo ""
echo "  4. Dans app/Models/User.php, ajouter :"
echo "     public function organization()"
echo "     { return \$this->belongsTo(Organization::class); }"
echo ""
echo "  5. Ajuster .env avec vos identifiants"
echo "  6. php artisan migrate"
echo "  7. php artisan db:seed --class=CategorySeeder"
echo "  8. php artisan serve"
echo "  9. Visiter http://localhost:8000/support"
echo ""
echo "═══════════════════════════════════════"
