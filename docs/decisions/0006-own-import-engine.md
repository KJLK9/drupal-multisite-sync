# 6. Site B uses its own import engine, not the Migrate API

Date: 2026-10-04 · Status: accepted

## Context
Site B imports the catalog of site A. The research in `docs/research/migrate-vs-own-engine.md` compares Migrate (core, Migrate Plus, Migrate Tools) with an own engine, based on the source of Drupal core 11.4.8, Migrate Plus 6.0.10 and Migrate Tools 6.1.4.

Migrate already provides an id map with change detection, reference lookups, rollback, per-run counters and, with Migrate Tools, delete detection (`--sync`). It does not provide a run as a first-class object shared by all phases, retry or a circuit breaker, an optional dead letter queue that keeps the payload and has an interface to retry, edit and discard, an atomic lock per import, or paging by `limit`/`offset`/`totalCount`.

## Decision
Build an own engine. Complete control over the run, the failure policy and the user interface outweighs reimplementing the id map and change detection. Migrate stays a source of ideas: its vocabulary (source, process/mapping, destination, id map with hash and status) and its events are a reference, but no Migrate classes are used.

## Consequences
- We own the parts Migrate gives for free: the id map with hash and change detection, reference lookups, deletion handling, locking and resuming. These get tests as first-class features, not afterthoughts.
- The README of the engine must say why it does not build on Migrate and name what it borrowed.
- Recorded as a risk: more code to maintain than extending Migrate would be.
