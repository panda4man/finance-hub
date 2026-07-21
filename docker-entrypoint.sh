#!/bin/sh
set -e

echo "Running database migrations..."
node dist/db/migrate.js

echo "Seeding category taxonomy..."
node dist/db/seed-categories.js

echo "Starting application..."
exec node dist/main.js
