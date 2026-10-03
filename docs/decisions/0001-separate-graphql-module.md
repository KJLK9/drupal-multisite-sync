# 1. GraphQL in a separate module

Date: 2026-10-03 · Status: accepted

## Context
Customers, products and product prices are domain entities. The data must be exposed over an API for import between two sites (site-a, site-b).

## Decision
Create `catalog_graphql`, an SDL-first, read-only GraphQL schema extension that depends on the domain modules. The entity modules know nothing about GraphQL.

## Consequences
- Domain modules stay usable without GraphQL; the API layer can be replaced (e.g. JSON:API) without touching entities.
- One schema can span all three entities (product → prices → customer).
- Mutations are out of scope; import writes happen on the receiving site.
