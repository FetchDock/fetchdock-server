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

# Start cron with logging (non-critical - only used for auto-updating downloaders)
cron > /var/log/cron.log 2>&1 &
sleep 1
if ! pgrep -x cron > /dev/null; then
    echo "WARNING: cron failed to start. Auto-updating downloaders will be disabled. Check /var/log/cron.log for details." >&2
fi

# If running as worker, wait for doctrine:migrations:status to report 'Already at latest version'
NODE_MODE=${NODE_MODE:-worker}
# Call the main entrypoint script with the bin/console command and the appropriate arguments
# to run the Symfony Messenger worker
# You can customize the arguments as needed
exec /usr/local/bin/docker-entrypoint php bin/console messenger:consume async --timeout=3600 --memory-limit=128M --sleep=5 --limit=100
