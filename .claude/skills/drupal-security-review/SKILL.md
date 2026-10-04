---
name: drupal-security-review
description: Security checklist for Drupal changes (access, escaping, SQL, caching, GraphQL exposure). Use before committing endpoints, forms or queries.
---

- Access: entity access handlers, route `_permission`/`_entity_access`, `accessCheck(TRUE)` on entity queries unless there is a documented reason.
- Output: no raw `#markup` with user input; use render arrays, `Xss::filter`, Twig autoescape.
- Database: entity query / Database API with placeholders only.
- Caching: correct contexts (`user.permissions`, `url`), tags and max-age; no per-user data in a shared cache.
- GraphQL: no field exposes private data; limit/offset bounded; no mutations unless intended; consider query depth/complexity limits.
- Secrets and files: nothing sensitive in config exports or git; uploaded files validated.
- Unpublished entities: use `PublishedEntityAccessControlHandler`; verify lists, counts and single lookups agree (kernel test per API).
- API keys: header only, dedicated low-privilege role and user; never log or commit a key.
