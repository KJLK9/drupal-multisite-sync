# 5. Unpublished items stay hidden, prices are unique, consumers use API keys

Date: 2026-10-04 · Status: accepted

## Decisions
- **Unpublished customers and products are only visible to administrators.** `PublishedEntityAccessControlHandler` (util/published_access) covers entity access, and a `hook_query_alter` adds `status = 1` to access-checked entity queries of any type that uses it. Core only does this for types with a query access handler (nodes, media), so lists, counts and paging would otherwise disagree with entity access. `product_prices` filters price queries the same way: a price is returned only if the user may view its product and customer.
- **One price per product and customer, guaranteed by the database.** A unique key (`ProductPriceStorageSchema`) is the guarantee; the `UniqueProductPrice` constraint reports it before the database does. Product, customer and price are required.
- **Machine access uses API keys** (`key_auth`, header `api-key` only; keys in query strings end up in logs). Consumers authenticate as a dedicated user with the `catalog_reader` role. GraphQL and JSON:API accept every authentication provider.
- **The author (`uid`) is not exposed over JSON:API** (`catalog_jsonapi`), so API consumers do not learn user UUIDs.
- **Trusted hosts** are the site's own DDEV hostname, derived from the site directory. There is no production environment.

## Consequences
- A new entity type gets the same behaviour by using the access handler and a `published` entity key.
- Duplicate prices cannot be saved by any code path; existing duplicates must be removed before `product_prices_update_11001`.
- The API key itself is a secret stored in the user account (database), never in config or git.
