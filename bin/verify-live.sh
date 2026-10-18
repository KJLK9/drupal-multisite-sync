#!/bin/bash
# The live check of the import engine (docs/decisions/0030): what site A holds
# must be exactly what site B has after the run set "catalog" has run, over the
# real GraphQL API, with the real key, into the real entities.
#
#   ddev composer verify:live
#
# It reads site A and changes site B only through its own import (the same one
# the demo runs). Needs: site A with data, the key in .env (SITE_A_API_KEY).
set -euo pipefail
cd "$(dirname "$0")/.."

OUT="$PWD/build/live"
mkdir -p "$OUT"
rm -f "$OUT"/a.json "$OUT"/b.json
DRUSH=vendor/bin/drush

echo "1. Site A: what it holds ..."
LIVE_OUT="$OUT/a.json" $DRUSH @ddev.site_a php:script "$PWD/bin/live/dump.php"
if ! grep -q '"customers": {' "$OUT/a.json" || [ "$(php -r '$d=json_decode(file_get_contents("'"$OUT"'/a.json"),true); echo count($d["customers"]);')" = "0" ]; then
  echo "Site A has no data. Make some first: ddev drush @ddev.site_a en catalog_test_data -y && ddev drush @ddev.site_a generate:test-data" >&2
  exit 2
fi

echo "2. Site B: the run set 'catalog' ..."
$DRUSH @ddev.site_b cr >/dev/null
$DRUSH @ddev.site_b import:run-set catalog

echo "3. Site B: what it holds ..."
LIVE_OUT="$OUT/b.json" $DRUSH @ddev.site_b php:script "$PWD/bin/live/dump.php"

echo "4. Comparing ..."
php "$PWD/bin/live/compare.php" "$OUT/a.json" "$OUT/b.json"

echo "5. Again: a second run changes nothing ..."
cp "$OUT/b.json" "$OUT/b-first.json"
$DRUSH @ddev.site_b import:run-set catalog >/dev/null
LIVE_OUT="$OUT/b.json" $DRUSH @ddev.site_b php:script "$PWD/bin/live/dump.php" >/dev/null
if ! cmp -s "$OUT/b-first.json" "$OUT/b.json"; then
  echo "A second run changed site B." >&2
  exit 1
fi
echo "Verified: site B equals site A, and a second run changes nothing."
