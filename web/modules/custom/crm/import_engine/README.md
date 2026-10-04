# Import engine

A configurable import engine for Drupal: pluggable sources, pagination, field
mappers and targets, with runs, retries and a dead letter queue. Why it is not
built on the Migrate API: `docs/decisions/0006-own-import-engine.md`. The plan
and milestones: `docs/plan-site-b.md`.

## Import definition

A config entity (`import_definition`) describes one import:

| Key | Meaning |
|---|---|
| `source` | Source plugin and its configuration (where to read). |
| `pagination` | Pagination plugin and configuration. |
| `authentication` | Authentication plugin and configuration. |
| `target` | Target entity type and bundle. |
| `mapping` | Rows of `target_field` and a `mapper` (plugin, `sources`, `settings`). |
| `delete_policy` | `unpublish` (default), `delete` or `ignore` for items gone from the source. |
| `resilience` | `max_attempts`, `backoff` (`fixed`, `linear`, `exponential`), `dlq_enabled`. |
| `pool` | Worker pool that processes the import's items. |

A mapper declares the **named sources** it needs, so one row can fill a field
from several source values:

```yaml
- target_field: field_rate
  mapper:
    plugin: money
    sources: { amount: price.number, currency: price.currency_code }
    settings: {}
```

Source paths are dotted names relative to one source item
(`price.customer.customer_code`); numeric segments index arrays.

The schema is fully validated (`FullyValidatable`); the allowed values of
`delete_policy` and `backoff` come from the enums `DeletePolicy` and
`BackoffStrategy`, the single source of truth.

Not validated yet, because the things they refer to do not exist yet: that a
plugin ID exists and that its configuration has the right shape, that the
target entity type and bundle exist, and that no target field is mapped twice.
