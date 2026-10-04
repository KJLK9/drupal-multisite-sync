# 8. Page fingerprints decide when to stop and what to skip

Date: 2026-10-04 · Status: accepted (the run-time parts land with the extract stage)

## Context
Paging can go wrong in two ways that look alike: a server that ignores the paging parameters returns the same page forever, and a large legitimate dataset has many pages. A cap on the number of pages would stop the first and cut the second short. Importing again and again also processes pages whose data did not change.

## Decision
- A page has a **fingerprint**: a hash of its decoded items, keys sorted, items in order. The whole page is hashed, never a sample of its bytes: a fingerprint that wrongly says "same" makes an update go unnoticed. The raw response is not hashed, since an envelope with a timestamp or request id would make equal data look different. The hash is xxh128 (fast, 16 bytes stored), not meant to resist an attacker.
- **Within a run**, a page whose fingerprint was already seen in this run is a repeat. Items have unique ids, so two pages of one run are never legitimately equal. After a number of repeats in a row (default 3) extraction stops with an error and the run is not marked complete, so nothing is swept. This also finds cycles (A, B, A, B). A match with a page of an *earlier run* never stops anything.
- **Across runs**, each page position remembers the fingerprint of the previous run. An equal fingerprint means the page is not processed again, but its source ids are still registered as seen in this run, or the sweep for deletions would remove them. The record also holds a fingerprint of the mapping and target configuration: after the definition changed the data must be processed again. A forced full run ignores the skip.
- **Setting up an import**: `check()` reads up to three pages with the real paging settings and reports a repeated page, which catches a misspelled paging parameter before any run exists.
- Skipping saves processing, not fetching. Not fetching at all needs the server (ETag or Last-Modified, an `updatedSince` filter) and is a later, separate option.

## Consequences
- The page store follows the storage budget in the plan: one row per page position, overwritten each run.
- Storing the ids of a page costs space proportional to the dataset, not to the number of runs.
- If boundaries shift (an insert early in an offset-paged source), later pages change fingerprint and are processed again; the item hash in the mapping table keeps that from rewriting unchanged entities.
