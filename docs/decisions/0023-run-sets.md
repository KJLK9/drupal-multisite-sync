# 23. Run sets run imports in order and stop at the first failure

Date: 2026-10-05 · Status: accepted

## Context
The imports of a catalog depend on each other: the agreements refer to accounts and items, so those have to be there first. Running three imports by hand in the right order, and not going on when one failed, is something a person forgets.

## Decision
- **A run set is a config entity** (`import_run_set`): a name and an ordered list of imports. It is validated like all configuration (at least one import, each one exists, none twice).
- **A set stops at the first import that goes wrong**: one that fails or is cancelled, one that no longer exists or is disabled, or one that cannot go on now (it waits for retries or for the source). What follows would build on data that is not there. An import that completed *with errors* (items in the dead letter queue) lets the next one start, unless the set says *stop on errors*: for a next import that needs all of the items of the previous one.
- **A set does not run imports itself.** `RunSetRunner` starts a run for each import, or continues the one that is not over, and drives it with the run driver. Everything a run does (retries, the circuit breaker, reports, the one active run per import) is the same inside a set.
- **The runner works for as long as its budget lasts and returns where it got to** (`SetProgress`, plain data). A command gives it one budget; the interface gives it fifteen seconds at a time between the steps of a progress bar (`RunSetBatch`), as `RunBatch` does for one run. Both therefore behave the same.
- **A set that was not finished starts again at its first import.** Keeping a position between calls would need a place to keep it, and starting again is cheap: an import with a run that is not over continues that run, and one whose pages did not change skips them. The alternative (a run entity for a set) was left out until there is a need to look back at past runs of a set.
- **An import in a set cannot be deleted** (the storage handler refuses, naming the sets), for the reason a connection in use cannot (ADR 0022): core would otherwise decide for us what happens to what depends on it.
- **Interface and command**: `/admin/config/system/import-engine/run-sets` (list, add, change with draggable rows, delete, run with a progress bar and the choice to read every page again) and `drush import:run-set <set> [--full] [--max-time] [--batch]`, with exit codes 0 (complete), 1 (stopped at a failure) and 2 (not over).

## Consequences
- Running the set from the interface needs `administer import runs`; managing sets needs `administer import definitions`.
- There is no schedule for a set; cron or a system scheduler can call the drush command.
- Imports of a set cannot run in parallel; if that is ever needed it is a different feature.
