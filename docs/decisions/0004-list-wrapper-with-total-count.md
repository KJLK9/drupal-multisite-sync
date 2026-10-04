# 4. Lists return `{ items, totalCount }`

Date: 2026-10-04 · Status: accepted

## Context
A client paging with `limit`/`offset` cannot tell when to stop, and an empty page is ambiguous (past the end, or no data).

## Decision
Every list field returns a wrapper (`CustomerList`, `ProductList`, `ProductPriceList`) with `items` and `totalCount`. This is a breaking schema change; nothing consumed the old shape yet.

- Top level (`catalog_entity_list` producer, any entity type): `totalCount` comes from an access-checked count query, so it matches the items a user can see (see ADR 0005).
- Nested `prices` (`catalog_prices`): the prices of a batch are loaded anyway, so `totalCount` counts the prices the user may view and is not limited by `limit`.

## Consequences
- Clients page until `offset + limit >= totalCount`.
- Entity types need access-checked queries that mirror entity access, otherwise totals leak hidden items.
