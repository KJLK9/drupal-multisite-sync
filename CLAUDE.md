# drupal-import-poc

Drupal 11 PoC (PHP 8.4, DDEV, MariaDB 11.8): a product catalog (customers,
products, product prices) exposed through a read-only GraphQL API.

## Commands (always via DDEV)
- `ddev composer check`: phpcs + phpstan + phpunit (the definition of done)
- `ddev composer phpcs` / `phpcbf`: Drupal + DrupalPractice standards
- `ddev composer phpstan`: level 8, no baseline
- `ddev composer rector`: Drupal deprecation scan (dry-run)
- `ddev composer test`: PHPUnit (`web/modules/custom/*/*/tests`)
- `ddev drush @ddev.site_a cex` / `cim` / `cr`: config export / import / cache
  rebuild. Always target a site (`@ddev.site_a`, `@ddev.site_b`, see
  `drush/sites/ddev.site.yml`); plain `drush` hits the unused `default` site.

## Layout
- `web/modules/custom/catalog/`: domain modules (`customers`, `products`,
  `product_prices`) and `catalog_graphql` (API layer, depends on the others,
  never the other way round).
- `web/modules/custom/util/`: reusable helpers (`money_field`).
- `config/<site>/sync`: exported config per site (`site_a`, `site_b`), set by
  `config_sync_directory` in each `web/sites/<site>/settings.php`. Committed.
  Contrib lives in `web/modules/contrib` (ignored).
- Decisions: `docs/decisions/` (one short ADR per architecture choice).

## Rules
- `declare(strict_types=1);` in every PHP file; typed properties and returns.
- Dependency injection everywhere; no `\Drupal::` statics. Hooks are OOP
  classes in `src/Hook/` with `#[Hook('...')]` (constructor DI, autowired);
  no `.module` files. Theme preprocess via `#[Hook('preprocess_<theme>')]`.
- Plugins via PHP attributes, not annotations. Tests via attributes.
- Config in code: change via UI/drush, then `cex` and commit. Schema changes
  need a `hook_update_N()` / post-update.
- GraphQL: never load per item in a resolver (N+1). Batch through a buffer
  (see `PriceBuffer`) or the built-in `entity_load` producers, and
  cover it with a query-count assertion in a Kernel test.
- Access checks and cache metadata on everything that outputs data. Query via
  the entity query API, never raw SQL with user input.
- Conventional commits (`feat:`, `fix:`, ...); enforced by GrumPHP.

## Definition of done
Run `ddev composer check`; fix everything before reporting done. A Stop hook
blocks finishing while phpcs/phpstan are red. Never add a phpstan baseline or
ignore rules; fix the code (type array shapes in docblocks). Write the failing test first when possible.

## Forbidden
- `drush` against a production DB, manual DB changes without an update hook.
- Leaving `dpm()`, `dd()` or `var_dump()` behind; `\Drupal::` statics.
- Skipping hooks (`--no-verify`) or force-pushing.

## Workflow
Plan first, small tasks per commit, run the `code-reviewer` agent after each
feature. Skills in `.claude/skills/` cover module, plugin, config, testing and
security patterns.
