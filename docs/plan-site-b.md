# Plan: the import engine on site B

Status: draft for discussion. Working name: `import_engine` (generic, publishable) plus `site_b_catalog` (the site-specific configuration). Decision to build it ourselves: ADR 0006.

## What it must do

1. Fetch from an HTTP source (REST, JSON:API or GraphQL) with an API key, with a choice of paging: `offset` + `limit`, `page`, URL-based (a `nextUrl` field in the response) or none.
2. Map source fields to a target entity, the mapper chosen by the target field type.
3. Import idempotently, resolve references between imports, handle items that disappear at the source.
4. Collect data across all phases of a run and report at the end through plugins ("X created, Y updated, Z deleted"), for example by mail.
5. Protect itself and the source: retries, an HTTP circuit breaker with a health check request, an optional dead letter queue.
6. Give the end user full insight and control in the UI, including processing items on request (not only by cron) and retrying, editing or discarding failed items in batches.
7. Design for, but do not build yet: per-import scheduling in its own Kubernetes CronJob.

## Architecture

A run has two stages, joined by a table of items. That table is the one structural decision most of the rest depends on.

```
 definition ──▶ RUN ──▶ [extract] fetch pages ──▶ item table ──▶ [process] map + upsert ──▶ [finish] sweep, report
                          │  pagination plugin        │  pending / processing / done /        │  reporters
                          │  circuit breaker          │  retrying / dead (the DLQ)            │
                          └─ health check             └─ payload kept, claimable by workers   └─ run counters
```

- **Extract** walks the pages of the source with the chosen pagination plugin and writes one row per source item: source id, payload, hash, run, state `pending`.
- **Process** workers claim pending items, map them, upsert the entity and record the result. Workers are interchangeable: cron, a drush command (`import:work --pool=heavy`), or a Batch API run started from the UI. This is what makes "process this item now" and per-pool Kubernetes workers possible without extra machinery.
- **Finish** runs once extract is complete and items are done: sweep for deletions, then the reporters.

### Why an own item table and not Drupal's Queue API
The core queue has no priority, cannot be inspected or edited, and a dead item cannot keep a payload to edit and retry. An item table gives all of that, plus the UI for free: it *is* the data the UI shows.

### Plugin types (PHP attributes, DI)
| Type | First implementations |
|---|---|
| Source | `Http` (method, URL, headers, query, JSON body with variables, so GraphQL fits) |
| Pagination | `OffsetLimit`, `Page`, `NextUrl`, `None` |
| Authentication | `ApiKeyHeader`; the secret is read from the environment by name, never stored in config |
| Field mapper | by target field type: string, text, boolean to published, money, reference by external id |
| Target | `Entity` (type and bundle) |
| Reporter | `Mail`, `Log` |

Pagination plugins work on a small **request specification** (URL, query, headers, body or GraphQL variables), not on a query string, so `offset` and `limit` can be a GraphQL variable as well as a query parameter.

### Run context and the run entity
One `RunContext` object lives for the whole run and is passed to every phase and plugin; phases dispatch events that plugins subscribe to. It is backed by an **`import_run` entity** (a content entity, one per run) so the UI, the mail report and a post-mortem of a crashed run all read the same data.

| Field | Meaning |
|---|---|
| `definition` | the import definition (config entity) the run belongs to |
| `status` | `queued`, `extracting`, `processing`, `finishing`, `completed`, `completed_with_errors`, `failed`, `cancelled` |
| `trigger` | `cron`, `drush`, `ui`, with the user for the last |
| `started`, `finished` | timestamps |
| `extract_complete` | whether every page was fetched; the sweep for deletions only runs when this is true |
| counters | extracted, created, updated, unchanged, skipped, failed, dead, deleted (see "Open: how counters are kept") |
| `summary` | short error and warning summary for the report |
| `context` | a map for data plugins want to carry between phases |

### Idempotency, references and deletions
- A mapping table (definition, source id, target entity, content hash, last seen run). An unchanged hash is `unchanged` and skipped.
- References between imports resolve through that table (a price finds its product by source id).
- Deletions: **mark and sweep.** Items seen in a run get `last_seen_run`; after a *complete* run, entities not seen are handled per definition: unpublish, delete or ignore. The sweep is skipped when extraction did not finish, so a failing source can never wipe the target. On site A an unpublished item disappears from the API, so "unpublish" is the natural default.

### Resilience
- **Retry with backoff** per item (`attempts`, `next_attempt_at`). After the last attempt the item moves to `dead`, or is dropped with a log entry when the dead letter queue is switched off for that import.
- **Circuit breaker per endpoint** with states closed, open and half-open. It opens after N consecutive failures (timeouts, 5xx) and, while open, fetch and process do not call the source. A **health check request** (URL, method, expected status) is sent at an interval; success half-opens the breaker and a successful real call closes it. An item that could not run because the breaker was open is *deferred* (`next_attempt_at`), not counted as a failed attempt, so an outage does not fill the dead letter queue.
- **Locking:** one extract per import at a time (core lock service with a lease), and items are claimed with an atomic update (`pending` to `processing`, worker token, lease expiry) so several workers can run safely and a crashed worker's items come back.

### The interface
Standard Drupal admin pages: import definitions (config entity forms), runs (progress, counters, phase timeline), items (filter by state, run, import), circuit breakers (state, last probe, manual trip and reset), and the dead letter queue. **Batch operations on request** (Batch API, progress bar): retry, edit payload (validated JSON), discard, and *process now* for any selection of pending items, so urgent items do not wait for cron.

### Target content model on site B
Own, deliberately different from site A so the mapping shows something: for example `Account` (from customer), `Item` (from product) and `Agreement` (from price), as content types in config. Differences worth showing: renamed fields, a money value split or converted, `status` to published, a reference resolved by external id, a text field with a format.

## Milestones (each ends in something demonstrable)

Progress on milestone 1: step 1 (module, config schema and the import definition) is done; steps 2-7 follow.

1. **Engine core, headless.** Definition entity, `Http` source and the four pagination plugins (unit-tested with mocked HTTP), item table, `Entity` target, run and counters, `import:run` and `import:work`. Demo: import customers from site A.
2. **Idempotency and references.** Mapping table, hashes, products then prices with references, mark and sweep with a delete policy.
3. **Interface, first cut.** Definitions, runs and items; start a run from the UI.
4. **Resilience.** Retry and backoff, circuit breaker with health check, dead letter queue, locking and claims.
5. **Batch controls and reporting.** Retry, edit, discard and process-now in batch from the UI; reporter plugins, the mail report.
6. **Finish.** README with the architecture and the "why not Migrate" section, tests in CI, documentation. Per-pool workers stay a documented design.

Tests per milestone: unit (pagination, breaker state machine, backoff), kernel (item table, claims, mapping, runner with mocked HTTP), functional (UI and batch). Fixtures come from recorded responses of site A, because CI cannot reach it.

## Decided

1. **Item table**, not Drupal's Queue API.
2. **Circuit breaker per endpoint** (host), shared by all imports that use it.
3. **Source fields are addressed with a dotted path** (`price.customer.customer_code`), no expression library. The list of items lives at an `items_path` on the response; field paths are relative to one item. Numeric segments index into arrays (`tags.0`); there are no wildcards or filters. The resolver sits behind an interface, so a library could replace it later.
4. **A form builder for the mapping from the start**, not YAML. The mapping is stored as structured config (a list of rows: target field, source path, mapper plugin and its settings), so it is built once and interpreted once, with no text format to parse.
5. **How we work:** in reasonable pieces, with every design choice made together before the code is written, so each part can be explained in an interview. For each piece I explain the options and a recommendation, you decide, I build it with tests and walk through it.

6. **Counters are derived from the items (option A below).**

## Counters: why derived from the items

Several workers process items at once, so a counter on the run must not be updated by loading and saving the entity (lost updates).

- **A. Derive from the items.** Every item records its `outcome` (created, updated, unchanged, skipped, failed). Live counters are one `GROUP BY` query; at the end of the run a snapshot is stored on the run entity. The sweep stores its own deleted count. No concurrency problem, one source of truth, counters can always be recomputed.
- **B. Atomic increments on the run.** Counter columns updated with `SET created = created + 1`. Simple to read, but a second place that can drift from the items and an entity cache to reset.

Chosen: A.
