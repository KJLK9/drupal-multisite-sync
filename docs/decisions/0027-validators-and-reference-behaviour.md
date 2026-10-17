# 27. Validators per mapping row and what a reference does when its target is missing

Date: 2026-10-17 · Status: accepted · Qualifies ADR 0010

## Context
A mapper turns a source value into a field value, but does not say whether it is a good one: an email field gets "n/a", a telephone number gets a name. Also, a reference to an item that is not imported yet was either a retry (`required`) or silently empty, and nothing in between.

## Decision
- **Validators are plugins on a mapping row**, in `validators` (a list, each with `plugin` and `settings`), run after the mapper on the value it made. Each declares the mappers it fits; the schema refuses a combination that does not fit (`ValidatorsFitMapper`). They are part of the mapping, so they are in the fingerprint: changing a check makes the items be processed again.
- **Eight validators**: `email`, `url`, `phone`, `regex`, `length`, `range`, `choice`, `not_blank`. A validator may normalize (a phone number to `+digits`, a choice to the spelling that is allowed); the normalized value is what is saved.
- **`on_invalid` is per row**: `fail` (default, a permanent mapping error, the item goes to the dead letter queue), `empty` (the field is left empty, the item is saved, an `invalid` event is written) or `skip_item` (the item is not saved, counted as skipped, and marked as seen so that the sweep does not treat it as gone).
- **A message never contains the value.** Events are kept 365 days and the viewer account can read them; a failing value is often personal data (an email address, a number). The reason says what is wrong ("is not a telephone number"), the field and the item say where.
- **Telephone numbers are checked by shape, not by a library.** 7 to 15 digits, separators and a `(0)` after the country code ignored, a country code required unless a default is configured. A library (libphonenumber) knows which numbers exist per country; it is a large dependency, and its data changes. For a portfolio engine whose goal is to reject what is clearly not a number, the shape is enough. A project that needs more can add a validator plugin.
- **A reference says `when_missing`**: `wait` (retry, as `required: true` was), `error` (end the item at once), `ignore` (leave empty, as `required: false` was). An update hook converts stored imports. Creating a stub of the missing item was considered and left out: it needs a second mapping (what to fill in the stub), and an import that later finds the real item has to know the stub is not "its" item.

## Consequences
- An `empty` or `skip_item` row can hide a source that went bad: the `invalid` and `skipped` events and the counts in the run show it, but nothing alerts on them yet.
- The wizard edits one check per field; more than one (written as config) is kept when the wizard saves.
- Validators work on one scalar value; a check across fields (end date after start date) is not possible.
