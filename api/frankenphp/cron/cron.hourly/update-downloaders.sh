#!/bin/sh
set -e

php /app/bin/console core:downloaders:update >> /var/log/update-downloaders.log 2>&1
