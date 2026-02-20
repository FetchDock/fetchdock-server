#!/bin/sh
set -e

# Start cron (non-critical - only used for auto-updating downloaders)
cron &
sleep 1
if ! pgrep -x cron > /dev/null; then
    echo "WARNING: cron failed to start. Auto-updating downloaders will be disabled." >&2
fi

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=${DB_WAIT_ATTEMPTS:-60}
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q 'SELECT 1' 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		# Only the php node should run migrations. Other nodes (worker) should wait and use doctrine:migrations:status
		NODE_MODE=${NODE_MODE:-php}
		if [ "$NODE_MODE" = "php" ]; then
			echo "[migration-coordinator] NODE_MODE=php -> running migrations (if any)"
			if [ "$( find ./migrations -iname '*.php' -print -quit )" ]; then
				if ! php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing; then
					echo "[migration-coordinator] ERROR: doctrine migrations failed" >&2
					exit 1
				fi
			else
				echo "[migration-coordinator] no migration files found, skipping"
			fi
		elif [ "$NODE_MODE" = "worker" ]; then
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
		else
			echo "[migration-coordinator] NODE_MODE=${NODE_MODE} -> skipping migrations"
		fi
	fi

	setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var || true
	setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var || true

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
