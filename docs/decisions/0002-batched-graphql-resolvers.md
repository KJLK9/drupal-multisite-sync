# 2. Batch GraphQL lookups through buffers

Date: 2026-10-03 · Status: accepted

## Context
Resolving `Product.prices` with a query per product costs N+1 queries for a list of N products.

## Decision
Reverse lookups go through a `BufferBase` service (`PriceBuffer`): all parents (products via `product_id`, customers via `customer`) requested in one execution are collected and their prices are loaded with one entity query per direction. Forward references use the core `entity_load` producer, which already buffers. A Kernel test asserts the query count for 50 products.

## Consequences
- Query count is constant in the number of products.
- Nested `prices(limit:)` is clamped like the top-level lists (default 50, max 100 per parent). Rows are loaded per batch before slicing, so the top-level `limit` still bounds the total work.
