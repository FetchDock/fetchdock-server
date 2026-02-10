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
if [ "$NODE_MODE" = "worker" ]; then
	MIGRATION_POLL_INTERVAL=${MIGRATION_POLL_INTERVAL:-2}
	MIGRATION_TIMEOUT=${MIGRATION_TIMEOUT:-300}
	START_TIME=$(date +%s)
	echo "[migration-coordinator] NODE_MODE=worker -> waiting for doctrine:migrations:status to report up-to-date (timeout ${MIGRATION_TIMEOUT}s)"
	while true; do
		# Check DB connectivity first
		if ! DATABASE_ERROR=$(php bin/console dbal:run-sql -q 'SELECT 1' 2>&1); then
			# If the Doctrine command exits with 255, an unrecoverable error occurred
			if [ $? -eq 255 ]; then
				echo "[migration-coordinator] DB unreachable (unrecoverable). Exiting." >&2
				exit 1
			fi
			# Otherwise keep waiting and retry until timeout
		fi

		# Check doctrine migration status output for the textual marker
		if php bin/console doctrine:migrations:status --no-interaction 2>/dev/null | grep -q "Already at latest version"; then
			echo "[migration-coordinator] migration status: Already at latest version -> proceeding"
			break
		fi

		# Fallback: if doctrine:migrations:status isn't available or output differs, try a secondary heuristic
		if php bin/console doctrine:migrations:status --no-interaction 2>/dev/null | grep -q "New.*0"; then
			echo "[migration-coordinator] migration status: no new migrations detected -> proceeding"
			break
		fi

		# Check timeout
		NOW=$(date +%s)
		ELAPSED=$((NOW - START_TIME))
		if [ "$ELAPSED" -ge "$MIGRATION_TIMEOUT" ]; then
			echo "[migration-coordinator] TIMEOUT waiting for doctrine:migrations:status after ${MIGRATION_TIMEOUT}s" >&2
			exit 2
		fi

		sleep $MIGRATION_POLL_INTERVAL
	done
fi

# Call the main entrypoint script with the bin/console command and the appropriate arguments
# to run the Symfony Messenger worker
# You can customize the arguments as needed
exec /usr/local/bin/docker-entrypoint php bin/console messenger:consume async --timeout=3600 --memory-limit=128M --sleep=5 --limit=100
