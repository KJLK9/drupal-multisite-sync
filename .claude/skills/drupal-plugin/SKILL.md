---
name: drupal-plugin
description: Write Drupal plugins (field types/widgets/formatters, blocks, GraphQL extensions, data producers) with PHP attributes, DI and cache metadata.
---

- Use attributes (`#[FieldType(...)]`, `#[Block(...)]`, `#[SchemaExtension(...)]`), never annotations.
- Inject services via `ContainerFactoryPluginInterface::create()`; no `\Drupal::` statics.
- Return `CacheableMetadata`/`#cache` (contexts, tags, max-age) for anything dependent on data or the user.
- Type all array parameters/returns in docblocks (`@return array<string, mixed>`) so phpstan level 8 passes.
- GraphQL: SDL-first; schema in `graphql/<id>.{base,extension}.graphqls`, resolvers in the extension plugin. Check entity access (`access` defaults to TRUE in entity_load) and add a Kernel test per query.
- GraphQL producers that touch storage must batch (extend `BufferBase`, return a `Deferred`); one query per parent item is a bug. Assert query counts in a Kernel test.
