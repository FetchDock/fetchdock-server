#!/bin/sh

# Simple wrapper around the main entrypoint to add worker-specific logic
# It basically just calls the main entrypoint script with the bin/console command
# and the appropriate arguments to run the Symfony Messenger worker

set -e

# Run composer install if the vendor directory is empty
if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
	composer install --prefer-dist --no-progress --no-interaction
fi

# Export current environment for cron jobs
printenv | grep -v '^PWD=' | grep -v '^SHLVL=' | grep -v '^_' > /etc/environment

# Start cron with logging
cron > /var/log/cron.log 2>&1 &
# Call the main entrypoint script with the bin/console command and the appropriate arguments
# to run the Symfony Messenger worker
# You can customize the arguments as needed
exec /usr/local/bin/docker-entrypoint php bin/console messenger:consume async --timeout=3600 --memory-limit=128M --sleep=5 --limit=100
