---
name: drupal-config-workflow
description: Export, review and import Drupal configuration (cex/cim, config split) and write update hooks for schema changes.
---

1. Make the change locally, then `ddev drush cex -y` and review `git diff config/`; commit config with the code that needs it.
2. Never edit the DB by hand. Entity/field storage changes need `hook_update_N()` (or a post-update) using `EntityDefinitionUpdateManager`.
3. Deploy order: `drush updb -y`, `drush cim -y`, `drush cr`.
4. Environment-specific config (site-a / site-b) goes in config split, not in the default sync directory. Secrets stay in `settings.local.php`/env vars, never exported.
