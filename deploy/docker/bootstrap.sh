#!/bin/bash
# Installs the two sites from their exported configuration, if they are not
# installed yet, and gives site A its test data and the reader that site B
# reads with. Safe to run again: a site that is installed gets the configuration
# of the image imported, and the seed data is left alone.
#
# Needs in the environment: SITE_A_HOST, SITE_B_HOST, the SITE_*_DB_* and
# SITE_*_HASH_SALT variables, SITE_A_API_KEY and ADMIN_PASSWORD, and
# optionally DEMO_PASSWORD for a read-only account on site B.
set -euo pipefail
cd /var/www/html

: "${ADMIN_PASSWORD:?ADMIN_PASSWORD is not set}"
SEED_CUSTOMERS="${SEED_CUSTOMERS:-30}"
SEED_PRODUCTS="${SEED_PRODUCTS:-40}"
SEED_COVERAGE="${SEED_COVERAGE:-40}"

drush_site() {
  local host="$1"
  shift
  drush --uri="http://${host}" "$@"
}

installed() {
  [ "$(drush_site "$1" status --field=bootstrap 2>/dev/null || true)" = "Successful" ]
}

# The database may still be starting.
wait_for_database() {
  local host="$1" tries=60
  until drush_site "$host" sql:query "SELECT 1" >/dev/null 2>&1; do
    tries=$((tries - 1))
    if [ "$tries" -le 0 ]; then
      echo "The database does not answer for ${host}." >&2
      exit 1
    fi
    sleep 3
  done
}

install_site() {
  local host="$1"
  wait_for_database "$host"
  if installed "$host"; then
    # Installed: bring it up to date with the configuration in the image.
    echo "${host} is installed; importing its configuration."
    drush_site "$host" config:import -y
    drush_site "$host" updatedb -y
    return
  fi
  echo "Installing ${host} from its configuration."
  drush_site "$host" site:install --existing-config -y \
    --account-name=admin --account-pass="${ADMIN_PASSWORD}" \
    --site-name="Import engine demo"
}

install_site "$SITE_A_HOST"
install_site "$SITE_B_HOST"

# Site A: the data to import, once, and the account that reads it.
if [ "$(drush_site "$SITE_A_HOST" sql:query "SELECT COUNT(*) FROM customer" 2>/dev/null || echo 0)" = "0" ]; then
  drush_site "$SITE_A_HOST" en catalog_test_data -y
  drush_site "$SITE_A_HOST" generate:test-data \
    --customers="$SEED_CUSTOMERS" --products="$SEED_PRODUCTS" --coverage="$SEED_COVERAGE"
  drush_site "$SITE_A_HOST" pmu catalog_test_data -y
fi
drush_site "$SITE_A_HOST" php:script /opt/import-engine/seed-site-a.php
# A read-only account to show the engine with, when a password is given.
if [ -n "${DEMO_PASSWORD:-}" ]; then
  drush_site "$SITE_B_HOST" php:script /opt/import-engine/seed-site-b.php
fi
drush_site "$SITE_A_HOST" cache:rebuild
drush_site "$SITE_B_HOST" cache:rebuild

echo "Done. Run the catalog: drush --uri=http://${SITE_B_HOST} import:run-set catalog"
