# Import audit

Optional. Shows where the imports left their traces, and takes them out again
when it is switched off (the engine does not depend on it, ADR 0031).

- **Event to entity:** on the events page of a run, the Target column becomes a
  link to the entity that was written (and to its history). An entity that is
  gone says "no longer exists".
- **Entity to history:** a tab *Import history* on an entity that an import
  wrote: what the imports did to it, newest first, with the import, the run
  (linked while the run still exists), the key in the source and the message.
  It needs the permission `view import history` and view access to the entity.

The page says what the list is not: a change made by hand is not in it, and
events are only kept for the number of days of the engine's setting
`retention_events_days`.

How it works: `HistoryRoutes` makes a route per content entity type that has a
page, `HistoryLocalTasks` the tab, `HistoryAccessCheck` hides the tab on any
entity that is not in the mapping table, `EntityHistory` reads the engine's
mapping and event log, and `AuditHooks` reworks the events page.

**Which fields changed:** the engine sends `ItemWritten` after every write (only
when someone listens). `ChangeRecorder` keeps the old and new value of each
changed field in `import_audit_change`; the history shows them beside the event.
They are kept for `retention_days` (30 by default, tab *Audit* of the engine
settings), purged by cron, and exist only from the moment the module was on.
See ADR 0031.
