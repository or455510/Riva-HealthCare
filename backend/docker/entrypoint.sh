#!/bin/sh
# RIVA-113: container entrypoint. Deliberately does NOT run migrations
# automatically — the master directive is explicit that migrations need a
# safe, reviewed strategy, not a side effect of "the container started."
# Set RUN_MIGRATIONS=true only when you've deliberately decided this
# container's boot should also migrate (e.g. a one-off release-step
# container in a real deploy pipeline), never as the default for every
# app-server replica.
set -e

if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "[entrypoint] RUN_MIGRATIONS=true — running php artisan migrate --force"
    php artisan migrate --force
fi

# Safe to always run: these just (re)build cached PHP arrays from the
# config/routes/views already baked into the image — no schema or data
# changes, and running them twice is a no-op.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
