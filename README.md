# drupal-import-poc

![CI](https://github.com/KJLK9/drupal-multisite-sync/actions/workflows/ci.yml/badge.svg)

Drupal 11 proof of concept: a small product catalog (customers, products,
per-customer product prices) exposed through a read-only GraphQL API, intended
for importing data between two sites.

## Architecture

```
catalog_graphql  ──depends on──▶  customers, products, product_prices
                                          │
product_prices ──references──▶ products, customers      all use ▶ money_field
```

- `web/modules/custom/catalog/*`: domain entities. `catalog_graphql` is the API layer.
- `web/modules/custom/util/money_field`: money field type (amount + ISO currency).
- Decisions are recorded in [docs/decisions](docs/decisions).

## Development

```bash
ddev start && ddev composer install
cp .env.example .env   # fill in the DB credentials and hash salts per site
ddev composer check   # phpcs (Drupal, DrupalPractice) + phpstan level 8 + phpunit
```

## Consuming the API

Site A serves `http://site-a.ddev.site/graphql/catalog` (GraphQL, explorer in
the admin UI) and `/jsonapi/...` (read-only, includes flattened by
`jsonapi_include`). Authenticate with an API key in the `api-key` header; keys
in query strings are ignored. Create a user with the `catalog_reader` role and
generate its key on `/user/<uid>/key-auth`; the key is a secret and lives only
in that account.

```bash
curl -H "api-key: $KEY" "http://site-a.ddev.site/graphql/catalog?query={customers{totalCount}}"
```

Development data (dev only, not part of the exported config):

```bash
ddev drush @ddev.site_a en catalog_test_data -y
ddev drush @ddev.site_a generate:test-data --customers=10 --products=20 --coverage=70
ddev drush @ddev.site_a pmu catalog_test_data -y
```

Quality tooling: Drupal Coder, PHPStan (+ drupal, deprecation rules), Drupal
Rector, PHPUnit, GrumPHP pre-commit hook (phpcs, phpstan, conventional
commits) and GitHub Actions CI.
