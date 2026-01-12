#!/bin/bash

# Deployment script for Rafik Biskra Backend
# Run this on the server after uploading backend-deploy.tar.gz

echo "=== Deploying Rafik Biskra Backend ==="

# Navigate to the deployment directory
cd ~/public_html/rafik-biskra.symloop.com

# Backup existing files if any
if [ -d "app" ]; then
    echo "Backing up existing installation..."
    mv app app_backup_$(date +%Y%m%d_%H%M%S) 2>/dev/null || true
fi

# Extract the archive
echo "Extracting files..."
tar -xzf backend-deploy.tar.gz

# Remove the archive after extraction
rm -f backend-deploy.tar.gz

# Copy production environment file
echo "Setting up production environment..."
cp .env.production .env

# Create necessary directories
mkdir -p storage/logs
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache

# Set proper permissions
echo "Setting permissions..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Install composer dependencies
echo "Installing dependencies..."
composer install --optimize-autoloader --no-dev

# Generate application key if not set
php artisan key:generate --force

# Clear and cache config
echo "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations
echo "Running database migrations..."
php artisan migrate --force

# Create storage link
php artisan storage:link

echo ""
echo "=== Deployment Complete ==="
echo "Your application should now be available at: https://rafik-biskra.symloop.com"
