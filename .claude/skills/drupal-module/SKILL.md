---
name: drupal-module
description: Create a new custom Drupal 11 module in this project (info.yml, services, permissions, tests). Use when adding or scaffolding a module.
---

1. Place it in `web/modules/catalog/<name>` (domain) or `web/modules/util/<name>` (helpers). Dependencies point inward: API/UI modules depend on domain modules, never the reverse.
2. Create `<name>.info.yml` with `core_version_requirement: ^11` and explicit `dependencies` (every field type's module, e.g. `drupal:text` for `text_long`).
3. Services in `<name>.services.yml`, constructor injection, autowire where possible. Hooks as `src/Hook/*Hooks.php` classes with `#[Hook('name')]` and constructor DI; do not create a `.module` file.
4. Permissions in `<name>.permissions.yml`; every route needs `_permission` or `_entity_access`.
5. Add at least one test under `tests/src/{Unit,Kernel,Functional}` (see drupal-testing).
6. Every PHP file: `declare(strict_types=1);`, typed signatures, doc comments with a description.
7. Finish with `ddev composer check`, then `ddev drush cex` if config changed.
