# drupal-import-poc

![CI](https://github.com/KJLK9/drupal-multisite-sync/actions/workflows/ci.yml/badge.svg)

Drupal 11 proof of concept, in two parts that meet over an API:

- **Site A** holds a product catalog (customers, products, per-customer prices)
  and serves it read-only through GraphQL (and JSON:API).
- **Site B** imports that catalog with an **import engine built for this
  project** (`web/modules/custom/crm/import_engine`): configurable sources,
  paging and field mappers, runs that survive failures, an interface to see
  and steer them, and content entities of its own with real relations.

Both sites run from one codebase (Drupal multisite, DDEV, PHP 8.4, MariaDB).
The engine is written to stand on its own: nothing in it knows about the
catalog; the catalog-specific part is configuration (`site_b_catalog`).

## What the engine does

| | |
|---|---|
| **Sources** | HTTP (REST, JSON:API, CSV) and GraphQL, with API-key authentication read from the environment (never stored in config). |
| **Paging** | Offset and limit, page number, a next-URL in the response, or none. The paging values can be GraphQL variables. |
| **Mapping** | Chosen by the type of the target field, as one table with a row per field, with suggestions from a sample of the source. Mappers: text, formatted text, number, yes/no, timestamp, money, reference to another import, join. |
| **Idempotent** | An item is only written when its hash changed; unchanged pages are skipped; items that disappear at the source are unpublished, deleted or ignored, with a safeguard against mass deletion. |
| **Resilient** | Retry with backoff, a circuit breaker per server with a probe request, a dead letter queue that keeps the payload and can be retried, edited and discarded from the interface. |
| **Observable** | A run is an object with status, counters and an event log of what changed or went wrong; reporters (log, mail) are plugins. |
| **Operable** | Drush commands, a progress bar from the interface, workers per pool, a run is resumable after a stop (SIGTERM included). |
| **Composable** | Connections (shared source and authentication) and run sets (imports in order, stopping at the first failure). |
| **Bounded** | Tables grow with the data and with what changes, not with the number of runs; retention is configurable. |

The design is written down as short decision records in
[docs/decisions](docs/decisions) (0001 to 0023), the original plan in
[docs/plan-site-b.md](docs/plan-site-b.md), and the module has its own
[README](web/modules/custom/crm/import_engine/README.md).

### Why not the Migrate API

Migrate covers more than one might expect (an id map with change detection,
lookups, counters, delete detection), and the research that compares it
requirement by requirement is in
[docs/research/migrate-vs-own-engine.md](docs/research/migrate-vs-own-engine.md).
What it lacks for a *recurring sync* is a **run as a first-class object**
shared by all phases, a failure policy (retry, circuit breaker), an optional
dead letter queue with the payload and an interface, an atomic lock per
import, and paging by `limit`/`offset`/`totalCount`. These could be bolted on
through subscribers and state keyed by migration ID, but the mismatch is
structural (Migrate moves rows once; a sync runs repeatedly). The cost is
recorded in [ADR 0006](docs/decisions/0006-own-import-engine.md): more code to
own, including the parts Migrate gives for free, which therefore have tests of
their own.

## Layout

```
web/modules/custom/
  catalog/    customers, products, product_prices (domain) and
              catalog_graphql, catalog_jsonapi (the API layer; site A)
  util/       money_field, published_access (shared helpers)
  crm/        import_engine      the engine, generic
              import_engine_ui   admin interface of the engine
              site_b_catalog     content model and imports of site B
config/<site>/sync   exported configuration per site
docs/                decisions (ADRs), plan, research, scheduling
```

## Try it

```bash
ddev start && ddev composer install
cp .env.example .env     # DB credentials, hash salts, SITE_A_API_KEY
ddev composer check      # phpcs + phpstan level 8 + rector + all tests
                         # (about 80 seconds); this is also what CI runs
```

Site B reads site A with an API key: create a user with the `catalog_reader`
role on site A, generate its key on `/user/<uid>/key-auth` and put it in
`.env` as `SITE_A_API_KEY`. Then import the configuration of site B (it
contains the connection to site A, the imports and a run set) and run it:

```bash
ddev drush @ddev.site_a en catalog_test_data -y      # development data
ddev drush @ddev.site_a generate:test-data --customers=10 --products=20
ddev drush @ddev.site_b cim -y
ddev drush @ddev.site_b import:run-set catalog      # accounts, items, agreements
```

The interface is under Configuration, System, **Import engine** on site B
(`https://site-b.ddev.site/admin/config/system/import-engine`): imports (with a
wizard), connections, run sets, runs, the dead letter queue, circuit breakers
and settings. Scheduling imports (cron, a scheduled job, Kubernetes) is
described in [docs/scheduling.md](docs/scheduling.md).

Site A serves `http://site-a.ddev.site/graphql/catalog` and `/jsonapi/...`,
authenticated with the key in the `api-key` header (keys in query strings are
ignored):

```bash
curl -H "api-key: $KEY" "http://site-a.ddev.site/graphql/catalog?query={customers{totalCount}}"
```

## Quality

- PHPStan level 8 without a baseline, Drupal Coder (Drupal and DrupalPractice),
  Drupal Rector, PHPUnit (Unit and Kernel, run in parallel against a database
  in memory), GrumPHP on commit (phpcs, phpstan, conventional commits) and
  GitHub Actions (a lint job and a test job).
- Tests assert the behaviour that matters: that a run can be stopped and
  continued, that table growth follows the data and not the number of runs,
  that the work queue never hands an item to two workers, that a deleted
  account takes its agreements with it, and that the same import run twice
  changes nothing.

### What has not been tried

Say it before it is asked. The interface (the wizard with its AJAX, dragging
rows, the CSS) is covered by Kernel tests of what the server builds, not by a
test in a browser; nothing runs JavaScript in CI. The mail reporter has not
sent to a real mail server. Scheduling in Kubernetes is a design, not
something that was run. Database partitioning of the history is not built in,
on purpose ([ADR 0009](docs/decisions/0009-storage-layout.md)).

## How this was made, and the part AI played

This is a portfolio piece, built on the author's own initiative, with no
client code in it. It was written **together with an AI coding assistant**
(Claude Code, from Anthropic), and that is not a footnote: most of the lines
were typed by the assistant.

How the work was divided:

- **The author decided.** What to build, in which order, and every
  architecture choice: the assistant proposed options with a recommendation,
  the author chose (several times against the recommendation or by asking for
  something else), and each choice is in an ADR with its trade-offs. Nothing
  was committed or pushed without the author saying so.
- **The assistant built, and was held to a standard.** Code, tests and
  documentation, under rules in [CLAUDE.md](CLAUDE.md) that it must follow:
  strict types, constructor injection and no `\Drupal::` statics, OOP hooks,
  attribute plugins, config in code, no phpstan baseline, tests first when
  possible. A hook blocks it from finishing while phpcs or phpstan are red,
  the definition of done is `ddev composer check` (what CI runs), and a
  read-only `code-reviewer` agent and a set of Drupal skills live in
  [.claude](.claude).
- **Commits say so.** Every commit made with the assistant carries a
  `Co-Authored-By` line.
- **What the assistant cannot do** is look at the interface, so layout and
  navigation were steered by the author's screenshots, and bugs found in the
  browser by the author.

The author is responsible for the result and can explain every decision in
it; the decision records are written so that this is possible. Reading the
history of `docs/decisions` is the quickest way to see how the design moved,
including the changes of mind.
