# 3. Price access follows product and customer; bounded lists

Date: 2026-10-03 · Status: accepted

## Decision
- A product price can be viewed by anyone who can view both its product and its customer (`ProductPriceAccessControlHandler`). Changing or creating prices requires `administer product_price`. A price missing either reference is admin-only.
- GraphQL list queries default to `limit: 50`, are clamped to 1..100, and `offset` is at least 0 (`catalog_clamp` producer). Out-of-range values are clamped, not rejected.

## Consequences
- No separate "view product price" permission to maintain; access cannot drift from the entities it describes.
- The price buffer preloads products and customers so the access check costs no extra queries per price.
