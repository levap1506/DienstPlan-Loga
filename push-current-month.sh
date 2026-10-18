#!/bin/sh
# Push the current month's shifts to LOGA.
# Used by /etc/cron.d/dienstplan-loga-push (runs as www-data).
# Extra arguments are forwarded, e.g. `push-current-month.sh --preview`.
set -eu
cd /var/www/dp.dr-ritz.de/loga
from=$(date +%Y-%m-01)
to=$(date -d "$from +1 month -1 day" +%Y-%m-%d)
echo "=== $(date '+%Y-%m-%d %H:%M:%S') push-shifts $from..$to $* ===" >> logs/cron-push.log
exec /usr/bin/php index.php --mode=push-shifts --from="$from" --to="$to" "$@" >> logs/cron-push.log 2>&1
