# Import engine UI

Pages to see and control the imports of the `import_engine` module. It is a
separate module, so the engine runs without it (drush, cron, workers).

## Pages

- **Import connections** (`/admin/config/system/import-engine/connections`,
  permission `administer import definitions`): the connections that imports
  share, with where they go, how they log in and which imports use them. Add,
  edit and delete; a connection in use cannot be deleted (ADR 0022). The
  wizard can use one (step 1) and save its own settings as one (step 2).
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
- **Import definitions** (`.../definitions`, permission `administer import
  definitions`): the list, and a wizard in five steps to add or change an
  import: source; paging and authentication; key and target; mapping; behaviour
  (what happens to what disappears, retries, circuit breaker, reports). Nothing
  is saved before the last step, and the whole definition is checked against its
  schema first. See ADR 0015.
- **Try the source** (steps 2 to 4 of the wizard): reads a few pages with the
  settings so far and shows what came back and the dotted paths of the values in
  the items, with their type and an example. The paths are named on the key step
  and offered while typing the sources of the mapping, and a source is filled in
  when a path matches the field (`field_customer_code` finds `customer_code` or
  `customer.code`).
- **Run now** (on the list of imports): starts a run, or continues the one that
  is not over, with a progress bar. The run is driven in calls of 15 seconds
  (`RunBatch`), so closing the browser leaves a run that can be continued here,
  or by a worker. A run that waits for retries, for another process or for a
  source that is down ends the batch with a message that says so.
- **Moving through the wizard**: a menu of steps at the top (a tick for a step
  that is in order, a count for one with problems) lets a person go to any step;
  what was typed is kept. Adding or removing rows, and trying the source, are
  AJAX, so the page does not jump. After a try to save, the problems are listed
  in the form in words. See ADR 0018.
- **The mapping** (step 4) is a table with a row for every field of the target:
  the field, its source or sources (dotted paths) and the mapper with its
  settings. A field without a source is left alone. "Suggest a mapping" fills in
  the empty fields from the sample, and a path of the sample can be clicked to
  use it. A GraphQL source starts its paging with the paging values as
  variables. See ADR 0021.

## Testing

There is no web server in CI, so the pages are tested as Kernel tests: the
render arrays that the controllers return, the access checks of the routes and
the forms submitted programmatically.
