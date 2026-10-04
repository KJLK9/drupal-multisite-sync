# 12. Running imports: a driver, a worker and thin drush commands

Date: 2026-10-04 · Status: accepted

## Context
Extract, process and finish are separate stages that each do a portion and report back. Something has to chain them, and it has to work from a terminal that runs for hours, from a cron run of a minute, from a Kubernetes pod that is stopped without warning, and later from a batch request in the interface.

## Decision
- **The order of the stages lives in one service, the `RunDriver`.** It reads the source in portions, processes items in batches and finishes the run, within a `RunBudget`. When the budget is spent it returns, and the next call continues, because every stage keeps its position in the run and in the work queue. Drush, cron and the interface only decide the budget; none of them repeats the loop.
- **A `RunBudget` is time, memory and a request to stop.** A worker checks it between units of work. SIGTERM and SIGINT request a stop (Kubernetes sends SIGTERM before it kills a pod): the item in hand is finished, nothing new is started, and the claimed items that were not started are given back at once, without costing an attempt. Memory is a share (80%) of the PHP limit, so a worker that grows is restarted by its supervisor instead of dying.
- **A `Worker` is separate from the driver.** It takes items of a pool, of any run, and finishes the runs whose items are done. That is what runs per pool in its own pod later. The `--once` option ends it when the queue is empty, which suits a job; without it it waits for new items.
- **Drush commands are thin:** `import:run` (starts a run or continues the unfinished one), `import:work`, `import:status`, `import:retry` (dead items become pending again), `import:cancel`. `import:run` exits 0 when the run completed, 1 when it failed or had errors and 2 when it is not over yet, so a scheduled job can react.
- **Cancel** skips the items that wait and ends the run. It refuses while extraction or finishing of that run is in progress, since those would write the old status back; stopping them first is up to the caller.
- **Retry of dead items** includes dead items of earlier runs. A worker handles them like any other item. Their run is over, so counters and page verification are not updated; the next run re-reads the pages that were never verified.
- **Cron only continues what is already running** and only when `cron_resume_seconds` is above 0 (the default is 0). What starts a run, and when, is the decision of whoever operates the site: cron, a scheduled job or a person. A schedule on the definition belongs to the Kubernetes design, which stays a design.

## Consequences
- A run can be driven by several processes: the locks of the stages and the claims on the items keep them apart, and the driver reports `busy` or `waiting` when there is nothing it may do.
- A run with items that wait for a retry stays open, and a call of the driver returns `waiting`; call it again later or let cron do it.
- A cancelled run has no counter snapshot; its items tell what happened.
