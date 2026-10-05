# 22. Connections are shared, and an import refers to them

Date: 2026-10-05 · Status: accepted (engine part; screens follow)

## Context
Every import carried its own URL, headers, timeout and way of logging in. Two imports from the same site meant typing (and keeping right) the same things twice, and a changed address had to be changed everywhere.

## Decision
- **A connection is a config entity** (`import_connection`): a source plugin with the settings that belong to a *connection* (URL, headers, timeout) and an authentication plugin. What belongs to a connection is declared by the source plugin itself (`connection_keys` and `required_keys` on the `#[ImportSource]` attribute), so a new source type needs no change elsewhere.
- **An import refers to it, it does not copy it** (a live reference). Changing the URL of a connection changes every import that uses it. A copy would have been simpler, but would have the same drift the connection is there to prevent.
- **An import with a connection says what the connection does not**: the query of a GraphQL import, the path of the items. It does not repeat the settings of the connection, and it does not choose its own authentication; both are refused by validation, so there is never a question which of two wins.
- **`ConnectionResolver` merges the two** when a source is built, in one place (`SourceFactory` uses it). Nothing else knows that connections exist. Without a connection the import is exactly what it was.
- **The rules are a constraint on the entity** (`ImportConnection`): with a connection, its source must be of the same kind, the import must not set what the connection owns, and what is required must be present in one of the two; without one, the import must have all it needs. The messages say what to do. A connection that does not exist is reported by `ConfigExists`.
- **A connection that imports use cannot be deleted**; the message lists the imports. The check is in the storage handler, not in `hook_predelete`: core deletes config that depends on an entity *before* that hook runs, which would have deleted the imports silently. The import depends on its connection (`calculateDependencies`), so a configuration import brings the connection first.
- **A run of an import whose connection is gone fails with a clear summary** instead of an error from deep in the source.
- **After the URL of a connection changes**, the person decides whether to read everything again ("Read every page again"); nothing is reset automatically.

## Consequences
- Secrets stay out of the connection too: authentication names an environment variable, as before.
- An import cannot be moved to another kind of source by changing only its connection; the validation says so.
- **The management screen** (`/admin/config/system/import-engine/connections`) lists the connections with their URL, authentication and the imports that use them, and adds, changes and deletes them. Its source section shows only the settings that belong to a connection, taken from the plugin's own form; the rules are those of saved configuration (the form validates the entity it would save). The kind of source of a connection in use cannot change. Changing the URL of a connection in use warns that the imports may need "Read every page again". A connection in use has no delete link, and its delete page explains why.
- The choice in the wizard comes next.
