#!/usr/bin/env bash
# Per-boot startup for the three dev servers, kept attached in one process so
# their logs stream together. Uses the `concurrently` package installed under
# backend/node_modules (a backend devDependency).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT/backend"
exec npx concurrently \
  --names api,backend,frontend \
  -c blue,green,magenta \
  "cd '$ROOT/api' && .venv/bin/uvicorn app.main:app --host 0.0.0.0 --port 8000" \
  "cd '$ROOT/backend' && php artisan serve --host 0.0.0.0 --port 8001" \
  "cd '$ROOT/frontend' && npm run dev"
