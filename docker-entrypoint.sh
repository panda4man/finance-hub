#!/bin/sh
set -e

: "${APP_KEY:?APP_KEY must be set — never regenerate post-deploy, it backs the encrypted cast on stored SimpleFin credentials}"

php artisan package:discover --ansi
php artisan config:cache

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force
  php artisan db:seed --class=CategorySeeder --force
  php artisan db:seed --class=CategoryRuleSeeder --force
  php artisan db:seed --class=ImportTemplateSeeder --force
  php artisan shield:generate --all --panel=admin --option=policies_and_permissions --no-interaction --ansi
fi

# The commands above run as root, so any file they create (e.g. today's daily
# log file) ends up root-owned — php-fpm workers run as www-data and can't
# write to it. Re-assert ownership every boot so web-triggered exceptions
# actually get logged instead of failing to write silently.
chown -R www-data:www-data storage bootstrap/cache

# supervisord needs to stay root (it spawns nginx, which drops its own
# workers to its configured user). The long-running artisan commands
# (queue:work, schedule:work) have no such need — run them as www-data so
# they can't re-root today's log file if it's created after this boot
# (e.g. a fresh day's log file appearing at midnight while these keep running).
if [ "$1" = "supervisord" ]; then
  exec "$@"
else
  exec su-exec www-data:www-data "$@"
fi
