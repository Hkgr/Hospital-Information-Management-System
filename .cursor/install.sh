#!/usr/bin/env bash
# Idempotent bootstrap for the api (FastAPI), backend (Laravel), and frontend
# (Next.js) services.
#
# It first ensures the system toolchain is present (PHP 8.4 + extensions,
# Composer, python3-venv) so the environment works from the default base image,
# then installs each service's dependencies. Every step is safe to re-run.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

php_ok() {
  command -v php >/dev/null 2>&1 && php -r 'exit(version_compare(PHP_VERSION, "8.4", ">=") ? 0 : 1);' >/dev/null 2>&1
}

echo "==> system toolchain"
if ! php_ok; then
  echo "    installing PHP 8.4 (Laravel's locked Symfony 8.1 requires PHP >= 8.4.1)"
  export DEBIAN_FRONTEND=noninteractive
  sudo apt-get update
  # ondrej/php provides PHP 8.4 on Ubuntu 24.04 (default repo only ships 8.3).
  sudo apt-get install -y --no-install-recommends software-properties-common
  sudo add-apt-repository -y ppa:ondrej/php
  sudo apt-get update
  sudo apt-get install -y --no-install-recommends \
    php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl php8.4-sqlite3 \
    php8.4-bcmath php8.4-gd php8.4-zip php8.4-intl unzip
  sudo update-alternatives --set php /usr/bin/php8.4
else
  echo "    PHP $(php -r 'echo PHP_VERSION;') already present"
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "    installing Composer"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
else
  echo "    Composer already present"
fi

if ! python3 -c 'import ensurepip' >/dev/null 2>&1; then
  echo "    installing python3-venv"
  sudo apt-get update
  sudo apt-get install -y --no-install-recommends python3-venv python3.12-venv python3-pip
fi

echo "==> api (FastAPI): python venv + requirements"
cd "$REPO_ROOT/api"
if [ ! -x .venv/bin/python ]; then
  python3 -m venv .venv
fi
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt

echo "==> backend (Laravel): composer + env + migrate + assets"
cd "$REPO_ROOT/backend"
composer install --no-interaction --prefer-dist
if [ ! -f .env ]; then
  cp .env.example .env
fi
if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi
touch database/database.sqlite
php artisan migrate --force
npm install
npm run build

echo "==> frontend (Next.js): npm install"
cd "$REPO_ROOT/frontend"
npm install

echo "==> install complete"
