# 7. The source contract: cursor pages, a check, and two kinds of failure

Date: 2026-10-04 · Status: accepted

## Context
Sources differ (an API with pages, a file with none, GraphQL with variables in a body), yet the engine must run them the same way, retry them sensibly and let a person set them up with confidence.

## Decision
- A source has one method to read, `fetchPage(?string $cursor): SourcePage`, and keeps no state. The cursor is an opaque string the caller stores on the run, so extraction can resume after a crash and the engine never depends on how a source pages.
- Failures during a run are `SourceException`s that say whether retrying can help (transient: connection, timeout, 408, 429, 5xx; permanent: other 4xx, malformed data). Retry and the circuit breaker act on that flag.
- Setting up an import uses `check()`, which never throws: it returns messages for the user and sample items. The same sample feeds the mapping form (path discovery) and, later, a dry run through the target's validation.
- Everything a source returns is decoded into JSON-shaped data (JSON, XML or CSV), so the rest of the engine handles one structure. A single XML element is treated as a list of one at the items path.
- Authentication is its own plugin type. A definition stores the name of the environment variable that holds a secret, never the secret.
- HTTP requests are described by an immutable `RequestSpec`, so a paging plugin can set a query parameter for REST and a body variable for GraphQL.

## Consequences
- Paging plugins (next step) plug into the HTTP source; a file source simply uses its own cursor.
- Error messages must never contain secrets: the source builds them from the method and URL (which has no query string), not from client exceptions.
- XML namespaces are ignored for now; CSV values are strings and mappers convert them.
