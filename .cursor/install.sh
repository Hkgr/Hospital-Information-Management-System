#!/usr/bin/env bash
# Idempotent repository bootstrap for the api (FastAPI), backend (Laravel), and
# frontend (Next.js) services. System-level tooling (PHP 8.4, Composer,
# python3-venv, Node) is provided by the base environment snapshot.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

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
