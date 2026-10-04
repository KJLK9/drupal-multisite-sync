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
The `graphql` source shares everything with `http` but describes the request
as a query with variables, and treats a 200 response with an `errors` list as a
failure, which an HTTP source would not notice.

## Pagination

A pagination plugin keeps no state: where it is, is the cursor. It sets the
paging values on the request (`applyCursor`) and reads the next cursor from the
response (`nextCursor`).

| Plugin | Cursor | Stops when |
|---|---|---|
| `offset_limit` | offset | an empty page, or the total is reached |
| `page` | next page number | an empty page, or the last page was read |
| `next_url` | the link from the response | the response has no link |
| `none` | none | always after one page |

- Paging values go in the query (`target: query`) or in the JSON body
  (`target: body`, name as a dotted path such as `variables.offset`), so one
  plugin serves REST and GraphQL.
- `offset_limit` advances by the items that came back, not by the page size
  asked for, and does not take a short page for the last one unless
  `stop_on_short_page` is on: a server that caps the page size would otherwise
  end the import after one page without an error.
- `next_url` only follows links on the same scheme, host and port as the
  configured URL, so the API key is never sent elsewhere.

`PageFingerprint` hashes the decoded items of a page (xxh128, keys sorted, the
whole page, never a sample). A check reads up to three pages and reports a page
that holds the same data as an earlier one: the server ignores the paging
settings. See ADR 0008.

## Authentication

`none` and `api_key_header`. A definition stores the *name* of the environment
variable that holds the key (`env_var`), never the key.
`config/settings.env.php` loads the project's `.env`, see `.env.example`.

## Paths

`items_path` (where the list is in the response), the key paths and the mapping
sources are dotted paths: `data.customers.items`,
`price.customer.customer_code`. `PathResolver` reads them, `PathDiscovery` lists
the paths in a sample item for the mapping form.

## Testing

HTTP is mocked with Guzzle's `MockHandler`; the fixture
`tests/fixtures/site_a_customers.json` is a recorded response of site A.

## Storage

Four stores with four lifetimes, so tables grow with the dataset and with what
changes, not with the number of runs (ADR 0009):

- `import_run` (entity): status, times and a snapshot of counters.
- `import_item` (table): the work queue. Workers claim items with a token and a
  lease (`ItemStorage::claim()`); a done item loses its payload.
- `import_page` (table): the fingerprint and keys of each page position, one
  row per position, overwritten by each run (`PageStore`).
- `import_event` (table): an append-only log of changes and problems, never of
  items that stayed the same (`EventLog`).

An import names its **source key**: one or more dotted paths whose values
together identify a source item (`ItemKey` builds the canonical key). It is set
on the definition (`source_key`), not on the source, so every kind of source
has the same notion of identity.

Retention is set in `import_engine.settings` (days; 0 keeps for ever) and the
`RetentionPurger` runs from cron in bounded batches.
