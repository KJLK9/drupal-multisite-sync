---
name: drupal-testing
description: Choose and write PHPUnit tests (Unit, Kernel, Functional) for Drupal code in this project. Use when adding behaviour or fixing a bug.
---

- **Unit** (`UnitTestCase`): pure logic, enums, value objects. No container. Fast; default choice.
- **Kernel** (`KernelTestBase`): entities, storage, services, GraphQL queries. Declare `$modules` explicitly and call `installEntitySchema()` / `installConfig()`.
- **Functional** (`BrowserTestBase`): forms, routes, permissions. Slowest; use sparingly.
- Location: `<module>/tests/src/{Unit,Kernel,Functional}`, namespace `Drupal\Tests\<module>\...`. Use attributes (`#[Group]`, `#[CoversClass]`), not annotations. Kernel and Functional tests also need `#[RunTestsInSeparateProcesses]` (rector enforces it).
- Write the failing test first, then the implementation. Run `ddev composer test` (or `ddev exec vendor/bin/phpunit -c phpunit.xml.dist <path>`).
- Kernel/Functional need `SIMPLETEST_DB` / `SIMPLETEST_BASE_URL` (set in `phpunit.xml.dist` for DDEV).
