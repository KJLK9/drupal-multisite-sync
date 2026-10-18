#!/bin/bash
# Mutation tests of the import engine (docs/decisions/0030-guarantee-tests.md).
#
#   ddev composer mutate                 coverage (if needed), then Infection
#   ddev composer mutate -- --filter=Process   only the files that match
#   REFRESH=1 ddev composer mutate       make the coverage again first
#
# The coverage comes from all the tests of the engine, made once with pcov
# (about 10 minutes) and kept in build/coverage; Infection then runs only the
# tests that cover the line it changed. A full run takes a long time: it is for
# before a release or after a big change, not for every commit.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f build/coverage/junit.xml ] || [ "${REFRESH:-}" = "1" ]; then
  echo "Making the coverage of the tests of the engine ..."
  composer coverage
fi
mkdir -p build/infection
exec vendor/bin/infection \
  --coverage=build/coverage \
  --skip-initial-tests \
  --threads="${INFECTION_THREADS:-6}" \
  --no-progress \
  "$@"
