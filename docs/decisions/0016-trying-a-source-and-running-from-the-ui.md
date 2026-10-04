# 16. Trying a source while setting up, and running from the interface

Date: 2026-10-04 · Status: accepted

## Context
The mapping asks for dotted paths into the items of a source, and a person who has not seen an item has to guess them. A run could only be started with drush, which leaves the interface a place to look, not to work.

## Decision
- **The wizard can try the source** with the settings filled in so far, without saving anything (`SourceSampler`): it reads the pages the source check reads, reports what it found as messages, and lists the dotted paths in the sample items with their type and an example. The result is plain values in the form state. A key that is not chosen yet is not judged, so the source can be tried on step 2.
- **The paths are offered where they are needed**: named on the key step, offered while typing the sources of the mapping, and a source is filled in when a path matches the field, with the prefix of a field and the differences in style ignored. A suggestion is only a default; nothing is chosen for the person.
- **A run is started from the list of imports** and driven in calls of 15 seconds by the Batch API (`RunBatch`), each call continuing where the previous one stopped. This costs almost nothing because the engine already works in portions that resume (ADR 0012). Closing the page leaves a run that can be continued from the same button, or by a worker.
- **One form starts or continues.** An import has one run that is not over; when it exists the form continues it, which also picks up a run that stopped because of an outage. An import that is switched off is not run.
- **A run that cannot go on says why** (waiting for retries, another process, an open circuit breaker) and does not keep the batch busy: a person is told to continue later or to start a worker.

## Consequences
- A batch needs the browser to stay open; long imports belong to workers. The interface is for starting, for small imports and for continuing.
- Trying a source calls it, with its authentication, like a run would; the person who may edit imports can therefore cause calls to the source.
