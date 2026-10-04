# 11. Finishing a run: verify, sweep, counters, reporters

Date: 2026-10-04 · Status: accepted

## Context
When every item of a run is handled, the run still has to be closed: pages must be marked as safe to skip next time, what disappeared from the source must be dealt with, the counters must be stored, the run needs a final status and someone must be told.

## Decision
- **Any worker may call `FinishStage::finish()` after a batch.** It does nothing while items are pending, being processed or waiting for a retry. A lock per run makes sure only one process finishes it, and the run is loaded again under that lock. A finish that stops halfway leaves the run in `finishing` and can be called again, because every step can be repeated.
- **Verify** marks pages as verified when none of their items failed. This also happens when the run ends as failed: it is true per page.
- **Sweep only after a complete extraction.** The items a complete run did not see are gone from the source. Items on pages that were skipped because they did not change count as seen (ADR 0008), so incremental runs can sweep too. A run whose extraction did not complete never sweeps, so a failing source cannot empty the target.
- **Delete policy.** `unpublish` marks the mapping `gone` and unpublishes the target; the item is not touched again by later sweeps, and when it returns it is published again. `delete` deletes the target and forgets the mapping, so a return starts from scratch. `ignore` does nothing. A target that cannot unpublish is left alone and the run says so in its summary.
- **Mass-deletion safeguard.** When more than `delete_threshold_percent` (default 20, 0 is no limit) of the known items would go in one run, nothing is swept and the run ends with errors. A source that suddenly returns an empty or half list is more likely broken than right.
- **Counters are a snapshot derived from the items** (ADR 0009) plus the extraction counters and the number swept. Items without a usable key were already counted as failed by the extraction.
- **Final status:** `failed` when extraction was not complete; `completed_with_errors` when items failed or died, or when the sweep was blocked or failed for some items; `completed` otherwise.
- **Reporters are plugins** (`log`, `mail`) listed on the import, each with its own settings and `only_on_problems`. They get a `RunReport`: status, counters, summary, duration and the first problems. A reporter that fails is logged and does not change the run or stop the other reporters. The mail text is composed in `hook_mail()`.

## Consequences
- The sweep is a loop over batches, so it can handle a large target without one big query. A batch in which nothing worked ends the loop, and the failing items are tried again by the next run.
- A run that is `completed_with_errors` because of the safeguard needs a person: raise the threshold, or find out what the source did.
- A run cannot be finished while an item still waits for a retry, so a long backoff keeps a run open for up to the retry cap (6 hours).
