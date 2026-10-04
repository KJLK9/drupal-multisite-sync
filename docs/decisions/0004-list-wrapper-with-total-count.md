# 4. Lists return `{ items, totalCount }`

Date: 2026-10-04 · Status: accepted

## Context
A client paging with `limit`/`offset` cannot tell when to stop, and an empty page is ambiguous (past the end, or no data).

## Decision
Every list field returns a wrapper (`CustomerList`, `ProductList`, `ProductPriceList`) with `items` and `totalCount`. This is a breaking schema change; nothing consumed the old shape yet.

- Top level (`catalog_entity_list` producer, any entity type): `totalCount` is the number of matches before the per-item view access filter, from a count query. Items are access-filtered, so a page can hold fewer items than `limit` while more exist.
- Nested `prices` (`catalog_prices`): the prices of a batch are loaded anyway, so `totalCount` counts only prices the user may view and is not limited by `limit`.

## Consequences
- Clients page until `offset + limit >= totalCount`.
- The top-level total can include items the user may not view; for products and customers access is uniform per permission, for prices it depends on product and customer.
