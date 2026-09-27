#!/usr/bin/env bash
#
# WBS Platform — local dev bring-up.
# Starts MariaDB + Redis (data under /home/user/services), runs migrations,
# seeds the foundation, and launches the app on 0.0.0.0:8080.
#
# Idempotent: safe to re-run. Intended for the sandbox/dev environment only.
set -euo pipefail

ROOT="/home/user/wbs-platform"
SVC="/home/user/services"
SOCK="$SVC/run/mysql.sock"

mkdir -p "$SVC/mysql-data" "$SVC/redis-data" "$SVC/run"

# --- MariaDB ---
if [ ! -d "$SVC/mysql-data/mysql" ]; then
  echo "Initializing MariaDB data directory..."
  mariadb-install-db --datadir="$SVC/mysql-data" \
    --auth-root-authentication-method=normal --skip-test-db >/dev/null 2>&1
fi

if ! mariadb --socket="$SOCK" -u root -e 'SELECT 1' >/dev/null 2>&1; then
  echo "Starting MariaDB..."
  /usr/sbin/mariadbd --datadir="$SVC/mysql-data" --socket="$SOCK" \
    --pid-file="$SVC/run/mysql.pid" --port=3306 --bind-address=127.0.0.1 \
    >"$SVC/run/mariadb.log" 2>&1 &
  for i in $(seq 1 30); do
    mariadb --socket="$SOCK" -u root -e 'SELECT 1' >/dev/null 2>&1 && break
    sleep 0.5
  done
fi

echo "Ensuring databases and app user..."
mariadb --socket="$SOCK" -u root <<'SQL'
CREATE DATABASE IF NOT EXISTS wbs_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wbs_platform_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wbs'@'127.0.0.1' IDENTIFIED BY 'wbs_dev_pw';
CREATE USER IF NOT EXISTS 'wbs'@'localhost' IDENTIFIED BY 'wbs_dev_pw';
GRANT ALL PRIVILEGES ON wbs_platform.* TO 'wbs'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wbs_platform_test.* TO 'wbs'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wbs_platform.* TO 'wbs'@'localhost';
GRANT ALL PRIVILEGES ON wbs_platform_test.* TO 'wbs'@'localhost';
FLUSH PRIVILEGES;
SQL

# --- Redis ---
if ! redis-cli -h 127.0.0.1 ping >/dev/null 2>&1; then
  echo "Starting Redis..."
  /usr/bin/redis-server --dir "$SVC/redis-data" --port 6379 --bind 127.0.0.1 \
    --save "" --appendonly no >"$SVC/run/redis.log" 2>&1 &
  sleep 1
fi

# --- App ---
cd "$ROOT"
echo "Running migrations (default + tests)..."
php spark migrate --all >/dev/null
php spark migrate --all -g tests >/dev/null
echo "Seeding foundation..."
php spark db:seed "WBS\\Admin\\Database\\Seeds\\FoundationSeeder" >/dev/null

echo ""
echo "Stack ready:"
echo "  MariaDB : 127.0.0.1:3306  (socket $SOCK)"
echo "  Redis   : 127.0.0.1:6379"
echo "  App     : starting on http://0.0.0.0:8080"
echo ""
exec php -S 0.0.0.0:8080 -t public public/index.php
