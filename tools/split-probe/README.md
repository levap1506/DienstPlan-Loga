# LOGA split probe tools

Read-only diagnostics for the LOGA Dienstsplit investigation.
See `docs/LOGA_SPLIT_INVESTIGATION.md` for the full context.

Run as `www-data` (needs `.env`, LOGA session cache and logs):

```bash
# All splits in a date range (fresh fetch, skips cache)
sudo -u www-data php loga/tools/split-probe/list-splits.php 2026-11-01 2026-12-31

# Shifts of specific PNRs on one date
sudo -u www-data php loga/tools/split-probe/check-date.php 2026-12-20 3017484 3028559
```

Both scripts only read from LOGA (`LogaFetcher` with `setSkipCache(true)`); they do
not create or delete anything.

The write probes used during the investigation live in `/tmp/opencode/` and are
described in `docs/LOGA_SPLIT_INVESTIGATION.md` §13. Do not run them on production
dates without a backup — they create/modify real LOGA splits.
