# 10. The process stage: target and mapper plugins, retry, change detection

Date: 2026-10-04 · Status: accepted

## Context
After extraction the items wait in the item table. Something has to map each item to a target value, write it, and decide what a failure means. The requirements ask for mapping chosen by field type, a target that is not fixed to one kind of entity, retries with backoff and an optional dead letter queue.

## Decision
- **Target is a plugin** (`entity` first). A later `file` or `config` target needs no change in the structure of the engine. The entity target lists its writable fields, validates before saving and saves as the configured `owner`, because reference validation checks the current user and a cron or drush run has none.
- **Mappers are plugins chosen by field type** (string, text, number, boolean, timestamp, money, reference). A mapping row names the target field, the mapper and, per mapper, named sources (dotted paths) and settings with their own config schema. A mapper declares which sources it needs, so a money mapper reads an amount and an optional currency.
- **Change detection by hash of the mapped values**, not of the source item. A source field that no mapping uses does not cause a write, and a changed mapping changes the hash (together with the processing fingerprint of ADR 0008).
- **Failures are permanent or transient.** A value that cannot be mapped, data that does not validate and a definition that does not work are permanent: the item ends at once. A reference to something not imported yet, and any unexpected error, are transient: the item is retried by `RetryPolicy` (fixed, linear or exponential from `retry_delay`, capped at 6 hours, no jitter so it stays testable) until `max_attempts` is reached.
- **An item that ends** goes to the dead letter queue and keeps its payload, or, when the import has none, is done as failed. Both leave an event.
- **Poison-item guard.** An item claimed more often than `max_attempts` allows (for example because its worker keeps crashing and never reports back) is dead on its next claim.
- **Only changes and problems are events.** An unchanged item is a counter, not a row, so the event table grows with change and not with the size of the dataset (ADR 0009).

## Consequences
- The mapping is validated when it is used (`PlanFactory`), per run, not per item, and a broken definition fails each item with the same message. A validator for the UI can reuse `PlanFactory`.
- A reference needs the other import to run first, or the item waits and retries. Ordering imports is left to the run starter later; retries make the order non-critical.
- Deleting what disappeared from the source is the finish stage (stuk 7).
