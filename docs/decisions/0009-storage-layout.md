# 9. Four stores with four lifetimes

Date: 2026-10-04 · Status: accepted

## Context
An import that reads 10 MB must not make a table grow by 10 GB a day. At the same time a user wants to see exactly what happened to an item and when. Keeping every item of every run would answer the second and break the first: a dataset of a million items synchronised daily adds over 100 GB a year, nearly all of it rows that say "unchanged".

## Decision
Separate the lifetimes instead of keeping one table for everything.

| Store | What | Grows with | Lifetime |
|---|---|---|---|
| `import_item` | the work queue of a run | items per run | short (7 days once done) |
| `import_event` | only changes and problems | what changes | long (a year, configurable) |
| `import_page` | fingerprint and keys per page position | the dataset | overwritten each run |
| `import_run` (entity) | status, times, counter snapshot | runs | long (90 days, configurable) |

- **Items and pages are plain tables**, not entities: there can be millions of rows, written, claimed and purged in bulk. Runs are few and shown in the interface, so they are an entity.
- **An item loses its payload when it is done** and keeps only the key, hash, outcome and times. The payload is stored compressed (JSON with gzip, five to ten times smaller). Dead items keep it, so they can be edited and retried.
- **The event log is append-only and never records "unchanged"**, so its growth is the number of real changes. The primary key includes the time and nothing refers to it, so a DBA can partition it by date and drop old partitions without a code change. Partitioning is not built in: Drupal's schema API does not manage partitions, the DDL is database specific, and a partition key must be part of every unique key, which the work queue's `(run_id, source_key)` cannot afford (a run crossing midnight). The queue's keys all include `run_id`, so it can be partitioned by run later.
- **Claiming** works as in Drupal's database queue: select candidates on an index, then update them under the same conditions with a unique token and a lease. Workers cannot take the same item, a dead worker's items come back, and a late worker cannot finish an item that was claimed again, because every change checks the token.
- **Hashes are 16 bytes** (xxh128 stored as binary), not 32 hex characters.
- **Retention is configuration** (`import_engine.settings`, in days, 0 keeps for ever) and the purge runs from cron in bounded batches.
- **A test enforces the rule**: several runs over the same dataset and the page, event and run rows are asserted to follow the dataset and the changes, not the number of runs.

## Consequences
- Counters of a running import come from aggregating the items; the run holds a snapshot written at the end.
- "What happened to this item" is answered by the event log, and "when did it first appear and last change" will be answered by the mapping table (one row per item).
- The queue is a place for items in flight, not an archive.
