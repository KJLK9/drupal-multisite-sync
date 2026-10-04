# 13. A circuit breaker per server, with a probe

Date: 2026-10-04 · Status: accepted

## Context
A source that is down should not be called again and again: it makes the outage worse, fills runs with failures and wastes time on timeouts. Retries with backoff exist per item (ADR 0010), but they protect nothing at the source.

## Decision
- **One breaker per server, shared by every import that reads from it.** The key is the host (and port) of the source (`SourceInterface::getEndpoint()`). One import finding out the server is down protects all of them. It is stored in the table `import_breaker`, so workers in other processes and pods see the same state.
- **States.** Closed: calls go through. Open: calls are refused without being made. Half open: calls go through; the first failure opens the breaker again, the first success closes it.
- **What counts.** Transient failures in a row (timeouts, connection errors, 408, 429, 5xx). Permanent errors (401, 404, malformed data) mean the server answered, so they are no outage and reset the count.
- **Opening.** After `threshold` failures in a row (default 5). Each import applies its own threshold to the shared count, so the strictest one opens it first. The cooldown before the first probe is `cooldown` seconds (default 60).
- **The probe.** When the cooldown is over, exactly one process claims the probe with a conditional UPDATE (a claim that lasts a minute, so a prober that dies does not block the breaker). The probe is the source's `probe()`: by default a request for the first page that is thrown away. If it fails, the breaker stays open and the cooldown doubles up to 15 minutes. If it answers, the breaker is half open. A probe that gets a permanent error counts as an answer: the server is up.
- **Every change is a single conditional UPDATE.** Whoever wins changes the state; there is no lock to hold or to lose.
- **What it guards.** Only reading pages from the source (`BreakerSource`, a decorator the run driver puts around the source). A refused call is a transient `SourceException`, which the extract stage already turns into an interrupted extraction that resumes later. Processing items needs no source (the payload is in the work queue), so it carries on during an outage.
- **By hand.** `drush import:breaker`, `import:breaker-trip` and `import:breaker-reset`. A breaker opened by hand has no probe: only a reset closes it.
- **Per import it can be switched off or tuned** with the `breaker` setting of the definition.

## Consequences
- A dedicated health URL is not needed yet: the probe asks for the first page, which works for GraphQL over POST too. A source plugin can override `probe()`.
- `defer()` on items (ADR 0009) stays available for a target that calls something remote; the entity target does not.
- Settings checks while an import is being set up (`check()`) are not guarded, since a person is looking at them.
