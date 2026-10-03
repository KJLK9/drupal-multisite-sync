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
ddev composer check   # phpcs (Drupal, DrupalPractice) + phpstan level 8 + phpunit
```

Quality tooling: Drupal Coder, PHPStan (+ drupal, deprecation rules), Drupal
Rector, PHPUnit, GrumPHP pre-commit hook (phpcs, phpstan, conventional
commits) and GitHub Actions CI.
