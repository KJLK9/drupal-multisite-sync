# Migrate API versus an own import engine for site B

Date: 2026-10-04 · Status: research input for an ADR (nothing decided)

## How this was checked

- **Core Migrate**: read in this repo, Drupal 11.4.8 (`web/core/modules/migrate`).
- **Migrate Plus 6.0.10 and Migrate Tools 6.1.4**: source cloned from git.drupalcode.org and read. Neither is installed in the project and **nothing was executed**, so every statement is about what the code does when read, not about observed behaviour.
- Marked **(not verified)** where something was inferred or remembered rather than seen in code.

## What Migrate is, in one paragraph

A **row-centric pipeline** that runs in one process: a *source* plugin yields rows, a per-property *process* pipeline transforms each row, a *destination* plugin saves it, and an *id map* table remembers source id → destination id, a hash of the row and a status (`imported`, `needs_update`, `ignored`, `failed`). The loop is `MigrateExecutable::import()` (`while ($source->valid())`, line ~195). Core ships the engine only: no UI to define or run a migration, no drush commands, no HTTP source. Migrations are YAML *plugins* in a module's `migrations/` directory, not config entities (`MigrationPluginManager`, lines 66-72). Migrate Plus adds a config-entity `Migration`, groups and an HTTP/JSON/XML/SOAP source with authentication. Migrate Tools adds drush commands, a batch executable and the per-run counters.

## Requirement by requirement

| # | Requirement | Migrate stack (core + Plus + Tools) | Verdict |
|---|---|---|---|
| 1 | Source, transform and target as plugins | Source, process and destination plugin types (core) | Covered |
| 2 | HTTP source with authentication | Plus `url` source, `http` fetcher with custom `headers` (an `api-key` header works) and basic, digest, NTLM and OAuth2 authentication | Covered |
| 3 | GraphQL source | The `http` fetcher has a `method` option but I found **no request-body option**, so GraphQL over POST is not supported. Our endpoint also accepts GET `?query=`, which would work | Gap (small) |
| 4 | Paging by `limit`, `offset` and `totalCount` | Plus JSON parser pager types are only `urls`, `cursor` and `page` | Gap (custom parser) |
| 5 | Idempotent upsert | Id map plus row hash; `track_changes` and `high_water_property` (core, `SourcePluginBase`) | Covered |
| 6 | References between imports | `migration_lookup` and stubs (core) | Covered |
| 7 | Per-run counters (created, updated, failed, ignored) | Tools `MigrateExecutable` counts them from `MAP_SAVE` events (`saveCounters`, creation versus update decided by `preExistingItem`). The counters live **on the executable instance** and are printed as progress; core's `MigrateImportEvent` carries only the migration and a message object | Covered for drush output; awkward to feed a mail report |
| 8 | "Deleted" detection | Tools `--sync`: a pre-pass reads the **entire source** to collect ids, then calls `$destination->rollback()` for map entries not seen (`MigrationImportSync`) | Covered, with limits: source read twice, and deleting is the only action (no "unpublish") |
| 9 | One run context shared by all phases | No run object. Process plugins get the executable and row; events get the migration and message. Cross-row data means a service keyed by migration id | Missing |
| 10 | Retry with backoff, circuit breaker | **None** in Plus or Tools (searched). Core: a row exception marks the row `failed` and continues; a source exception aborts the run (`MigrateExecutable` lines ~164 and ~267). A missing HTTP response throws a `MigrateException` (Plus `Http` fetcher) | Missing |
| 11 | Dead letter queue | Partial: failed rows stay in the id map with messages in `migrate_message_*` tables and a **read-only** report at `/admin/reports/migration-messages`. The map stores ids, a hash and a status and the messages are text, so the failed item's payload is not kept; a retry would re-read the source (inferred from the table definitions, not observed). No switch to make it optional, no replay or discard UI | Partial |
| 12 | One run per import at a time | Status flag in key-value (`migrate_status`), checked at line ~128 and set at line ~158, so **not atomic**; no lock in core, Plus or Tools. Tools ships `migrate:reset-status`, i.e. a stuck "importing" status is a known situation | Weak |
| 13 | Run in the web UI, in batches | Tools `MigrateBatchExecutable` and routes; core has no time or memory handling in the executable (searched) | Covered by Tools |
| 14 | Define an import as config, edit in a UI | Plus config entity `Migration` and groups. A UI to create definitions is **(not verified)** | Partly covered |
| 15 | Mapping chosen per field type, not written per field | Process pipelines are explicit per destination property; nothing selects a mapper from the field type | Missing |
| 16 | Per-import scheduling (own Kubernetes CronJob) | Migrate has no scheduler; whatever starts a run decides. Equally easy either way | Neutral |

## What this changes

- **Migrate covers more than I assumed**, including per-run counters and delete detection, which I had expected to be missing. Anyone interviewing a senior Drupal developer may ask about these, so the ADR must not claim otherwise.
- **The gaps that matter** are 9, 10, 11 and 12, plus 3 and 4 for our API. Those are exactly what the requirements stress: a run context across phases with a pluggable report, optional DLQ with payload and UI, resilience against a failing source, and safe concurrent runs.
- **Row-centric is the underlying mismatch.** Migrate models a one-off move of rows. A recurring *sync* wants a first-class **run**: its statistics, its phases, its failure policy. You can bolt that on, but through subscribers, state keyed by migration id and an executable subclass.

## Three options

**A. Migrate plus extensions.** Keep Migrate/Plus/Tools and write: a GraphQL or `totalCount` source and paging, a run-context service fed by events, a persisted report and mail subscriber, a retrying, circuit-breaking fetcher decorator, a DLQ store with payload, a lock around imports, an unpublish action for sync. You inherit id map, change detection, lookups, rollback, drush, batch and UI. Cost: you live inside its extension points; some of the above (counters on the executable, `--sync` double read) are workarounds around its model.

**B. Own engine.** Full control; the run context and policies are first-class. You must rebuild what Migrate gives for free: id map with hash and change detection, reference lookups across imports, rollback, an entity destination with validation, locking, batching and resuming, and a drush and UI surface. These are well understood but not small, and each is a place for subtle bugs (e.g. a non-atomic lock, which is what Migrate itself has).

**C. Own engine, Migrate's vocabulary.** As B, but borrow the concepts (source, process, destination, id map with hash and status) so that Drupal developers recognise it, without depending on Migrate's classes. Process plugins cannot be reused as is: they are bound to `MigrateExecutableInterface` and `Row`.

## Where the honest case for B or C rests

1. A **run** as the central object (context, phases, statistics, reporter plugins) is the thing Migrate does not have.
2. **Failure policy as configuration** (retry, circuit breaker, optional DLQ with payload and UI) is absent from the whole stack.
3. **Soft delete** (unpublish) and a single pass over the source for deletions. On site A, an unpublished item disappears from the API, so "missing from source" and "unpublished" are the same event.
4. **Self-selecting field mapping** and **config-entity definitions with a UI** as one coherent model.

The weak points to be ready to answer: id map and change detection are reimplemented, Migrate has years of edge-case fixes, and it is more code to maintain.

## What to settle before writing the ADR

1. **Time-boxed spike (1-2 hours):** import `customers` from site A with Migrate Plus (GET with `api-key` header, `page` pager) and note exactly where it hurts. That turns this table into observed fact.
2. **Scope for the first version** of the engine if B or C: run context, id map with hash, idempotency, retry and circuit breaker, a report plugin. DLQ UI and per-import scheduling afterwards.
3. **Delete semantics:** unpublish, delete or ignore, per definition.
4. **Whether the engine should be publishable on drupal.org** as a separate generic module. That raises the bar on documentation and tests, and makes the "why not Migrate" answer part of the project README.
