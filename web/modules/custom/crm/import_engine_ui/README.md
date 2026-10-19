# Import engine UI

Pages to see and control the imports of the `import_engine` module. It is a
separate module, so the engine runs without it (drush, cron, workers).

## Pages

Every page has a line at the top that says which item it is about (the name,
a label for its state, a few facts), and most have a panel at the side that
says in plain sentences what the page shows or what the item will do. The words
of jargon have an info icon with their meaning and a link to the **help page**
(`.../help`), which also explains the big picture and the steps of a first
import. The texts are in `Help/Glossary.php` and `Help/DefinitionSummary.php`;
the Dutch translation is in `translations/nl.po`, and a test keeps it in step
with the code.

- **Run sets** (`/admin/config/system/import-engine/run-sets`, permission
  `administer import definitions`; running needs `administer import runs`):
  named lists of imports with draggable rows, run in order with a progress bar
  (ADR 0023).
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
- **Clone and templates** (on the list of imports): "Clone" opens the wizard
  with everything of an import filled in, as a new import that is switched off.
  An import marked as a template is listed apart, never runs, and is used with
  "Use as template". A new import starts switched off.
- **In the wizard**: while a path of the source is typed, the real value it has
  in the sample shows under the field; "Preview the result" shows per field what
  the mapping makes of the first sample item (the value, or why it would fail);
  a panel with the import in plain sentences grows with the steps.
- **Run now** (on the list of imports): starts a run, or continues the one that
  is not over, with a progress bar. The run is driven in calls of 15 seconds
  (`RunBatch`), so closing the browser leaves a run that can be continued here,
  or by a worker. A run that waits for retries, for another process or for a
  source that is down ends the batch with a message that says so.
- **Moving through the wizard**: a road of steps at the top (a circle per step:
  a tick when it is in order, an exclamation mark when it has problems; hover
  and keyboard focus show that they can be clicked) lets a person go to any
  step; what was typed is kept. Adding or removing rows, and trying the source,
  are AJAX, so the page does not jump. After a try to save, the problems are
  listed in the form in words. See ADR 0018.
- **The mapping** (step 4) is a table with a row for every field of the target:
  the field, its source or sources (dotted paths) and the mapper with its
  settings. A field without a source is left alone. "Suggest a mapping" fills in
  the empty fields from the sample, and a path of the sample can be clicked to
  use it. A GraphQL source starts its paging with the paging values as
  variables. See ADR 0021.

The Target column of the events of a run can be reworked by other modules
(`hook_import_engine_ui_event_targets_alter`); the optional module
`import_audit` uses it to link an event to the entity it changed.

## Testing

There is no web server in CI, so the pages are tested as Kernel tests: the
render arrays that the controllers return, the access checks of the routes and
the forms submitted programmatically. What only a browser shows (the form cache
between the steps, AJAX, hover and focus, the layout) is covered by the
Playwright tests in `e2e/` (`ddev composer e2e`, see its README); they run
locally, inside DDEV, against site B.
