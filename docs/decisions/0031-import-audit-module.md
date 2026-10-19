# 31. Import audit: from an event to the entity and back, in an optional module

Date: 2026-10-19 · Status: accepted (phase 1) · Builds on ADR 0010 and ADR 0011

## Context
The engine writes an event for every change, failure and refusal, with the target entity (`account:30`) in it. But on the run pages that target was plain text, and on the entity there was no way to see what the imports had done to it. "Why did this change, and when?" is a question people will ask on the day after a run.

## Decision
- **A separate module, `import_audit`** (`crm/import_audit`), depends on the engine and its UI; the engine depends on nothing of it. It can be switched off without a trace: nothing in the engine calls it.
- **Event to entity.** The events page of a run lets modules rework the Target column (`hook_import_engine_ui_event_targets_alter`, one call for the whole page, so one load per entity type and not per row). `import_audit` turns `account:30` into a link to the entity, plus a link to its history for those who may see it; an entity that is gone says so; one that the person may not view stays text.
- **Entity to history.** A tab "Import history" on every content entity type that has a page of its own, with the route and the tab derived from the entity types. The tab exists only for an entity that an import wrote (an access check against the mapping table), so it does not show on nodes edited by hand. Access also needs the permission `view import history` and view access to the entity itself.
- **The data is the event log that already exists.** The page finds the import items that became the entity (`MappingStore::forTarget`, with an index added by update 10003) and shows their events, newest first.
- **Who may see it.** The history shows the messages and keys of the source, a second way to data that the run pages protect with `view import runs`. So `view import history` is a restricted permission, to give on purpose; it is not implied by view access to the entity.
- **Caching.** The tab shows only for an imported entity, which the engine cannot announce with a cache tag without knowing of this module; the answer is cached for a minute.
- **Honest about its limits**, on the page itself: only changes made by imports (a change made by hand is not in it), and only as long as the engine keeps its events (`retention_events_days`, 365 by default; the page says the number).

## Not in phase 1
The events say *that* an entity was created, changed, hidden or refused, and the message, but not *which fields* changed from what to what. That needs the engine to say what it wrote. The plan (phase 2): the engine sends one event after every write, with the old and new values of the mapped fields, only when someone listens (so it costs nothing without the module), and `import_audit` keeps them in its own table with its own, shorter retention, truncating long values. Values can be personal data, which is why the permission is separate from `view import runs`.

## Consequences
- The engine gets one small, general method and an index (`forTarget`); the UI gets one alter hook; nothing else changes for those who do not enable the module.
- With the module enabled, the access check runs a query on every page of an entity type with a page of its own, to decide whether to show the tab; that is an indexed lookup on a small table.
- Tabs of Gin are folded in a menu on narrow pages; the link is there but not always in view.
