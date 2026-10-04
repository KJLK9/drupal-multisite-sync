# Import engine

A configurable import engine for Drupal: pluggable sources, pagination, field
mappers and targets, with runs, retries and a dead letter queue. Why it is not
built on the Migrate API: `docs/decisions/0006-own-import-engine.md`. The plan
and milestones: `docs/plan-site-b.md`.

## Import definition

A config entity (`import_definition`) describes one import:

| Key | Meaning |
|---|---|
| `source` | Source plugin and its configuration (where to read). |
| `pagination` | Pagination plugin and configuration. |
| `authentication` | Authentication plugin and configuration. |
| `target` | Target entity type and bundle. |
| `mapping` | Rows of `target_field` and a `mapper` (plugin, `sources`, `settings`). |
| `delete_policy` | `unpublish` (default), `delete` or `ignore` for items gone from the source. |
| `resilience` | `max_attempts`, `backoff` (`fixed`, `linear`, `exponential`), `dlq_enabled`. |
| `pool` | Worker pool that processes the import's items. |

A mapper declares the **named sources** it needs, so one row can fill a field
from several source values:

```yaml
- target_field: field_rate
  mapper:
    plugin: money
    sources: { amount: price.number, currency: price.currency_code }
    settings: {}
```

Source paths are dotted names relative to one source item
(`price.customer.customer_code`); numeric segments index arrays.

The schema is fully validated (`FullyValidatable`); the allowed values of
`delete_policy` and `backoff` come from the enums `DeletePolicy` and
`BackoffStrategy`, the single source of truth.

Not validated yet, because the things they refer to do not exist yet: that a
plugin ID exists and that its configuration has the right shape, that the
target entity type and bundle exist, and that no target field is mapped twice.

## Sources

A source plugin hands out items one page at a time and keeps no state:

```php
$page = $source->fetchPage($cursor);   // SourcePage: items, nextCursor, total
$check = $source->check();             // SourceCheck: messages and sample items
```

- `fetchPage()` is used during a run. The cursor is an opaque string the caller
  stores (on the run), so extraction can resume. It throws a `SourceException`
  that is either *transient* (timeout, 408, 429, 5xx: retry, counts towards the
  circuit breaker) or *permanent* (401, 404, malformed data: do not retry).
- `check()` is used while an import is being set up. It never throws: it
  returns messages for the person filling in the form and a few sample items
  to build the field mapping from.

`http` reads JSON, XML or CSV (`format: auto` detects it). Everything is
decoded into the same JSON-shaped data by `ResponseDecoder`, so the rest of the
engine only deals with one structure. The URL has no query string, parameters
go in `query`, so secrets and paging values never appear in messages.
GraphQL is a POST whose `body` holds the query.

## Authentication

`none` and `api_key_header`. A definition stores the *name* of the environment
variable that holds the key (`env_var`), never the key.
`config/settings.env.php` loads the project's `.env`, see `.env.example`.

## Paths

`items_path` (where the list is in the response), `id_path` (where the id is in
an item) and the mapping sources are dotted paths: `data.customers.items`,
`price.customer.customer_code`. `PathResolver` reads them, `PathDiscovery` lists
the paths in a sample item for the mapping form.

## Testing

HTTP is mocked with Guzzle's `MockHandler`; the fixture
`tests/fixtures/site_a_customers.json` is a recorded response of site A.
