#!/bin/sh
set -e

cd /var/www/html

# Install PHP dependencies automatically if vendor/ is missing
if [ ! -f "vendor/autoload_runtime.php" ] && [ -f "composer.json" ]; then
    echo "[entrypoint] Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist
fi

# Wait for MySQL to accept connections before running migrations
if [ -n "$DATABASE_URL" ] && [ -f "bin/console" ]; then
    echo "[entrypoint] Waiting for MySQL database to be ready..."
    ATTEMPTS=0
    MAX_ATTEMPTS=30
    until php -r '
        $url = getenv("DATABASE_URL");
        $parts = parse_url($url);
        $host = $parts["host"] ?? "database";
        $port = $parts["port"] ?? 3306;
        $user = isset($parts["user"]) ? rawurldecode($parts["user"]) : "ticket_user";
        $pass = isset($parts["pass"]) ? rawurldecode($parts["pass"]) : "ticket_pass";
        $db   = ltrim($parts["path"] ?? "/ticketing_db", "/");
        try {
            new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass, [PDO::ATTR_TIMEOUT => 2]);
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    ' >/dev/null 2>&1; do
        ATTEMPTS=$((ATTEMPTS + 1))
        if [ "$ATTEMPTS" -ge "$MAX_ATTEMPTS" ]; then
            echo "[entrypoint] ERROR: MySQL did not become ready in time."
            exit 1
        fi
        echo "[entrypoint] MySQL not ready yet (attempt $ATTEMPTS/$MAX_ATTEMPTS)... retrying in 2s"
        sleep 2
    done

    echo "[entrypoint] Running database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

# Ensure Symfony cache, logs, and sessions directories are writable by Apache (www-data)
mkdir -p var/cache var/log var/sessions public/uploads/events
chown -R www-data:www-data public/uploads
chown -R www-data:www-data var

echo "[entrypoint] Starting Apache web server..."
exec "$@"
