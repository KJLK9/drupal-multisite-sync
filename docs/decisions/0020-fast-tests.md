# 20. A fast test suite: parallel, with the database in memory

Date: 2026-10-05 · Status: accepted

## Context
The suite had grown to 500 tests and `composer check` took over 13 minutes, every time it was run. Style, static analysis and deprecation checks together take ten seconds: it was all the tests. Almost all of them are Kernel tests, which Drupal runs in a process of their own each, and each of those creates and drops dozens of tables.

## What was measured
- A Kernel test takes about 1.5 seconds, nearly all of it set up.
- Running tests in parallel helped less than expected (3x with ten processes), and more processes made it slower. The processes were idle for 60% of the time, with iowait, and the server had created and dropped 148,000 tables: each of them is a file and an fsync on a disk.
- The same suite against a database in memory took 69 seconds, with the same processes.
- A cache for compiled PHP files shared between the processes gained about 20% per test.

## Decision
- **The tests run in parallel** with ParaTest, one process per test (`--functional`), which is what Kernel tests need anyway. `composer test` does this, and `composer test -- <path or options>` runs a module or one test.
- **The database for the tests is in memory.** DDEV has a second database server, `testdb`, whose data directory is tmpfs, and the web container is told to use it for the tests (`SIMPLETEST_DB`). The databases of the sites stay on disk: this server only holds tables that tests create. CI does the same with a tmpfs on its database service. Without the variable the tests still work, against the site database, only slowly.
- **PHP caches compiled files in a directory** while the tests run (`build/php.d/opcache.ini`, set for that command only), since every test is a new process.
- **Unit tests have a suite of their own** (`composer test:unit`, a fraction of a second) and `composer check:fast` runs the checks without the Kernel tests, for the moments in between.
- **CI has two jobs that run side by side**, the checks and the tests, so a failure says which kind it is and the checks do not wait for the tests.

## Result
`composer check` went from over 13 minutes to about 80 seconds, with the same 503 tests, and a module takes seconds.

## Consequences
- The data in the test database is gone when DDEV stops. That is the point.
- Tests must not depend on each other or on the order, nor on files that are shared; Kernel tests were already built that way and the suite has been run in parallel several times without a failure.
- The memory of the machine is used by the processes and by the tables in memory: with 12 processes about 3 GB. More processes than that made it slower.
