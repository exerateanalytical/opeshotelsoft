#!/usr/bin/env bash
# Runs `npm run build` one agent at a time (builds write to public/build, which is shared).
lock=/tmp/opes-build.lock
for i in $(seq 1 150); do
  if mkdir "$lock" 2>/dev/null; then trap 'rmdir "$lock"' EXIT; cd "$(dirname "$0")/.." && npm run build; exit $?; fi
  # drop a stale lock older than 10 minutes
  find "$lock" -maxdepth 0 -mmin +10 -exec rmdir {} \; 2>/dev/null
  sleep 2
done
echo "could not get the build lock" >&2; exit 1
