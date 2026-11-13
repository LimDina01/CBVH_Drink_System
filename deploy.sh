#!/usr/bin/env bash
set -e

echo "🚀 Deploying CBVH Drink System (Production)..."

# 1. Pull latest changes
git pull origin main

# 2. Ensure deploy user & web server have write access
sudo chown -R $USER:www-data storage bootstrap/cache database 2>/dev/null || true
sudo chmod -R 777 storage bootstrap/cache 2>/dev/null || true
sudo chmod -R 775 database 2>/dev/null || true

# 3. Install PHP production dependencies (skips dev tools)
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 3. Build optimized frontend assets
npm ci || npm install
npm run build

# 4. Run database migrations safely
php artisan migrate --force

# 5. Ensure storage link exists
php artisan storage:link || true

# 6. Cache configuration, routes, and views for maximum performance
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Set strict production permissions
sudo chown -R $USER:www-data storage bootstrap/cache database
sudo chmod -R 777 storage bootstrap/cache
sudo chmod -R 775 database

# 8. Reload PHP-FPM
sudo systemctl reload php8.5-fpm || sudo systemctl reload php8.4-fpm || sudo systemctl reload php8.3-fpm || sudo systemctl reload php-fpm || true

echo "✅ Deployment completed successfully!"

