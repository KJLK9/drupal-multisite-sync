# 25. What an item may do to the target

Date: 2026-10-12 · Status: accepted · Qualifies ADR 0008, 0010 and 0011

## Context
A review of the engine looked for places where the data could end up wrong without anything failing. Five were found and are now closed. Each is a case where the engine believed something that was no longer true.

## Decision
- **A run never takes back what a later run has seen.** The mapping keeps `last_seen_run` and `last_changed_run` with `GREATEST`. A dead item of run 4 that is retried after run 5 has imported the same key would otherwise lower the mark to 4, and the sweep of run 5 would take a live entity for gone.
- **An item of an older run does not overwrite the later one.** When the key was handled by a later run, the item is *skipped* and an event says so: its payload is older than what the target holds. A dead item that is retried after its data was fixed in a later run ends here.
- **What was deleted by hand is made again.** An item whose hash equals the stored one is only "unchanged" if the target still has the entity (`TargetInterface::exists()`, one indexed query per item). Without that, an entity an editor deleted stayed missing until the source value changed. The event says it was made again.
- **The hash covers the target settings** (entity type, bundle, owner) as well as the mapped values, so the same values for another owner are written again. One consequence is that after this change every item is written once on the next run. And **an item that was written as another entity type is not touched as this one**: the mapping remembers the type (`TargetInterface::entityTypeId()`), and a mismatch is treated as not known, so the ID of a node is never used for a term.
- **A claim is renewed while a worker still has items.** The lease covers the whole batch when claimed; a batch that takes longer than the lease gave its last items to a second worker, which meant two writes of the same item, and an attempt counted against healthy items. The claim of the items that are left is renewed once a third of the lease has passed, with the token it was claimed with, so a worker that lost its claim cannot renew it.
- **Cancel reaches items a worker already holds.** The cancel skips what nobody has claimed; a worker that held a batch would go on writing. Every item checks whether its run is cancelled, with one indexed query, and is skipped if so.
- **Extraction works from what is stored.** A process that loaded a run, and was overtaken by another that extracted or finished it, would write its old position over the new one. Under the lock the stored run is compared with the one held; if they differ the call is busy and the caller loads the run again.

## Consequences
- One more query per unchanged item (`exists`) and one per item for the cancel check. These are reads on primary keys and are small next to what an item already costs (mapping, payload, saves); they are the price of not trusting a hash about something the engine does not own.
- A dead item that is skipped because a later run handled the key ends as *skipped*, not as failed, and the queue shows it as done.
- Not done here, and noted: the rows of a deleted import (mapping, pages, items) are not removed, and mappings of items that went away under the unpublish policy are kept for ever.
