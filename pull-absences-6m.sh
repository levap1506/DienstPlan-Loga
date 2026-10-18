#!/bin/sh
# Pull LOGA absences for 3 months before .. 3 months after the current month.
# Conflict strategy: keep-local-all -> keep an existing shift instead of
# overwriting it with an absence.
# Used by /etc/cron.d/dienstplan-loga-push (runs as www-data).
# Extra arguments are forwarded, e.g. `pull-absences-6m.sh --preview`.
set -eu
cd /var/www/dp.dr-ritz.de/loga
base=$(date +%Y-%m-01)
from=$(date -d "$base -3 months" +%Y-%m-01)
to=$(date -d "$base +4 months -1 day" +%Y-%m-%d)
echo "=== $(date '+%Y-%m-%d %H:%M:%S') pull-absences $from..$to (keep-local-all) $* ===" >> logs/cron-pull-absences.log
exec /usr/bin/php index.php --mode=pull-absences --from="$from" --to="$to" --strategy=keep-local-all "$@" >> logs/cron-pull-absences.log 2>&1
