# 15. A wizard for import definitions, saved only when it is complete

Date: 2026-10-04 · Status: accepted

## Context
An import definition is a lot of settings across a source, paging, authentication, a key, a target, a mapping, retries, a circuit breaker and reports. It is stored as config, so what is saved must be valid, and it is exported with the rest of the site.

## Decision
- **Five steps in one form** (source; paging and authentication; key and target; mapping; behaviour). A person can go back and forth; a step is checked when it is left.
- **The definition lives in the form state and is saved once.** There is no half made definition in the configuration, nothing to clean up after an abandoned form, and nothing in a configuration export that was never finished. The alternative, saving a disabled definition after each step, was rejected for that reason.
- **The whole definition is checked against its config schema before it is saved.** The steps check what a person can get wrong in a form; the schema is the judge of the result. A problem it finds is shown with the name of the step it belongs to, and that step opens.
- **The settings of a plugin are the form the plugin describes itself** (ADR 0014). Choosing another plugin replaces its settings by AJAX, at the level of the whole section; the form of a plugin does not need to know where it sits.
- **The mapping has one row per field of the target.** The mapper choices follow the type of the field, the paths of the sources are the names the mapper declares, and the settings are the mapper's own form. A field can be filled by one row only. Rows have stable IDs, so adding or removing one never disturbs what was typed in the others, and Form API keeps the input of the rows that stay.
- **The ID of an existing import cannot change**, since runs, items and the mapping store refer to it.
- **Deleting is refused while a run of the import is not over.**

## Consequences
- Going to another step discards nothing, but leaving the form does: nothing is saved until the end. That is the price of a clean configuration.
- The settings forms are tested one by one (ADR 0014); the wizard is tested as a whole through programmatic submissions of every step and by rendering each step. What a browser adds (the AJAX calls and the JavaScript of the machine name field) is not covered by these tests.
- A step to try the source and suggest the paths of the mapping from sample items is possible on top of this, since the engine already has `check()` and path discovery.
