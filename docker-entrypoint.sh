#!/bin/sh
set -e

: "${APP_KEY:?APP_KEY must be set — never regenerate post-deploy, it backs the encrypted cast on stored SimpleFin credentials}"

php artisan package:discover --ansi
php artisan config:cache

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force
  php artisan db:seed --class=CategorySeeder --force
  php artisan db:seed --class=CategoryRuleSeeder --force
fi

exec "$@"
