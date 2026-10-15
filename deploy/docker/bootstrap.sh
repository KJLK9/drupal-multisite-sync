#!/bin/bash
# Installs the two sites from their exported configuration, if they are not
# installed yet, and gives site A its test data and the reader that site B
# reads with. Safe to run again: a site that is installed gets the configuration
# of the image imported, and the seed data is left alone.
#
# What counts as installed is what Drupal says: its installer sets the state
# "install_time" as its very last step. A site without it is empty, or an
# install that stopped halfway (a database that went away, a pod that ran out of
# memory); that one is emptied and installed again. Nothing here swallows an
# error: a step that fails fails the job, and the job is tried again.
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

# Whether the installer of Drupal reached its end. The state is only readable
# on a site that has the tables, so a failure here means "not installed".
installed() {
  local time
  time="$(drush_site "$1" state:get install_time 2>/dev/null || true)"
  [ -n "$time" ] && [ "$time" != "null" ]
}

install_site() {
  local host="$1"
  wait_for_database "$host"
  if installed "$host"; then
    # Installed: bring it up to date with the configuration in the image.
    echo "${host} is installed; importing its configuration."
    # The caches first: the definitions of the entity types are cached, and a
    # configuration import with the definitions of the old code drops what the
    # new code added (a setting of a config entity that it does not know yet).
    drush_site "$host" cache:rebuild
    drush_site "$host" config:import -y
    drush_site "$host" updatedb -y
    return
  fi
  local tables
  tables="$(drush_site "$host" sql:query "SHOW TABLES" | grep -c . || true)"
  if [ "$tables" -gt 0 ]; then
    echo "${host} was not installed completely (${tables} tables); starting again."
    drush_site "$host" sql:drop -y
  fi
  echo "Installing ${host} from its configuration."
  drush_site "$host" site:install --existing-config -y \
    --account-name=admin --account-pass="${ADMIN_PASSWORD}" \
    --site-name="Import engine demo"
  installed "$host" || { echo "The installation of ${host} did not finish." >&2; exit 1; }
}

install_site "$SITE_A_HOST"
install_site "$SITE_B_HOST"

# Site A: the data to import, once, and the account that reads it. A query that
# fails stops the job: it is not taken for "no data".
customers="$(drush_site "$SITE_A_HOST" sql:query "SELECT COUNT(*) FROM customer")"
if [ "$customers" = "0" ]; then
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
