# Import engine UI

Pages to see and control the imports of the `import_engine` module. It is a
separate module, so the engine runs without it (drush, cron, workers).

## Pages

- **Import runs** (`/admin/config/system/import-engine/runs`, permission `view
  import runs`): the runs, newest first, with status, duration and counters. A
  run that is not over shows live counters, derived from its items.
- **A run** (`.../runs/{run}`): its figures, the items (filter by state) and the
  events, each paged. A person with `administer import runs` can cancel a run
  that is not over.
- **Dead letter queue** (`.../dead-letter`, filter by import): the items that
  ran out of attempts, with their errors. With `administer import runs`: retry
  selected (a worker handles them), handle selected now (a Batch API run with a
  progress bar), discard selected (after a question) and edit the payload of one
  item (validated JSON, optionally retried after saving). Every action is
  written to the event log with the name of the person.
- **Circuit breakers** (`.../breakers`): the state of the breaker per server,
  and a confirmation form to open one by hand or to close it.

## Testing

There is no web server in CI, so the pages are tested as Kernel tests: the
render arrays that the controllers return, the access checks of the routes and
the forms submitted programmatically.
