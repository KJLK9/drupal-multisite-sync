# 8. Page fingerprints decide when to stop and what to skip

Date: 2026-10-04 · Status: accepted, refined when the extract stage was built

## Context
Paging can go wrong in two ways that look alike: a server that ignores the paging parameters returns the same page forever, and a large legitimate dataset has many pages. A cap on the number of pages would stop the first and cut the second short. Importing again and again also processes pages whose data did not change.

## Decision
- A page has a **fingerprint**: a hash of its decoded items, keys sorted, items in order. The whole page is hashed, never a sample of its bytes: a fingerprint that wrongly says "same" makes an update go unnoticed. The raw response is not hashed, since an envelope with a timestamp or request id would make equal data look different. The hash is xxh128 (fast, 16 bytes stored), not meant to resist an attacker.
- **Within a run**, a page whose fingerprint was already seen in this run is a repeat. Items have unique ids, so two pages of one run are never legitimately equal. After a number of repeats in a row (default 3) extraction stops with an error and the run is not marked complete, so nothing is swept. This also finds cycles (A, B, A, B). A match with a page of an *earlier run* never stops anything.
- **Across runs**, each page position remembers the fingerprint of the previous run. An equal fingerprint means the page is not processed again, but its source ids are still registered as seen in this run, or the sweep for deletions would remove them. The record also holds a fingerprint of the mapping and target configuration: after the definition changed the data must be processed again. A forced full run ignores the skip.
- **Setting up an import**: `check()` reads up to three pages with the real paging settings and reports a repeated page, which catches a misspelled paging parameter before any run exists.
- Skipping saves processing, not fetching. Not fetching at all needs the server (ETag or Last-Modified, an `updatedSince` filter) and is a later, separate option.

## Refinements made when building the extract stage
- **A page is only skipped when it is verified.** Remembering a page's fingerprint when it is read is not enough: if one of its items then failed (retrying, dead, or ended failed), the next run would see an unchanged page and skip it, and the item would never be tried again until the data happened to change. A page record therefore has a `verified` flag, set when the run ends for pages whose items all went well. Items remember the position of their page, so the pages with failures are one cheap query. Only a verified page with an equal fingerprint is skipped.
- **A page with an item without a usable key is never verified**, so that item is reported again each run; persistent bad source data then costs one event per run per bad item, which is small and accurate.
- **A skipped page still counts as seen.** Its keys are marked as seen in the mapping table (`last_seen_run`), which makes the sweep one indexed query (everything not seen in the latest run). Without this the sweep would take everything on a skipped page for deleted.
- **A changed mapping, target or key voids all page records** of that import (a fingerprint of that configuration is kept once per import). A run can also be forced to process every page.
- **Extraction is resumable.** The run keeps the position of the next page; the fingerprints of the pages read so far come from the page store, so repeats are still found after a resume.
- **Failures.** A temporary source problem interrupts extraction and keeps the position; the next call continues. A permanent problem, or too many repeated pages, ends extraction abnormally: the run moves on to processing, so what is already queued (valid data) is handled, but it is not marked complete, ends as failed and does not sweep.
- **One extractor per import** at a time, by a persistent lock that is renewed per page and expires if the process dies; one unfinished run per import.

## Consequences
- The page store follows the storage budget in the plan: one row per page position, overwritten each run.
- Storing the ids of a page costs space proportional to the dataset, not to the number of runs.
- If boundaries shift (an insert early in an offset-paged source), later pages change fingerprint and are processed again; the item hash in the mapping table keeps that from rewriting unchanged entities.
