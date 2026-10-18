# 30. Guarantee tests for the import engine, and mutation testing

Date: 2026-10-19 · Status: accepted · Qualifies ADR 0020

## Context
`ddev composer check` runs about 800 tests and takes two and a half minutes, which is as much as can be asked before every commit and in CI. Most of them test one part of the engine. What was missing is a test of the promises the engine makes as a whole: that a run which is stopped, crashed or repeated ends in the same place; that a source that fails removes nothing; that every way of paging reads every item once. And there was no way to know how good the tests were: if someone broke the engine by accident, would a test notice?

## Decision
- **A second suite, `guarantee`**, in `import_engine/tests/src/Guarantee`, with its own configuration (`phpunit.guarantee.xml.dist`). It is not part of `check` or of CI: it takes about 75 seconds with six processes (one test of 5,000 items takes 40 of them) and is meant to be run before a commit: `ddev composer verify`. It is written as promises, each a test class:
  - *Pipeline*: every item arrives once for every shape of data (none, one, exactly a page, one more, many); a second run changes nothing, not even a revision; one changed field is one update; the numbers add up.
  - *Interruption*: a run that is cut into single pages, whose workers die between batches and whose source fails in the middle ends with the same accounts and the same counters as an uninterrupted one (the same items are read by two imports, one driven in one go and one interrupted).
  - *Deletion*: each policy does what it says; a source that cannot be read or suddenly returns almost nothing removes nothing; the threshold is exact; an account that returns is the same account.
  - *Resilience*: retries follow the strategy to the second and never exceed the attempts; the dead letter queue can be fixed, retried and discarded; an outage costs the site nothing.
  - *Pagination*: through the real HTTP source against a server that pages in each of the four ways, at seven sizes of data (a server that ignores the paging is stopped; a wrong total is not believed).
  - *Validation*: every validator with every `on_invalid`, and that the value never appears in what is written down.
  - *Reference*, *Concurrency* (no item to two workers; a worker that is too late loses), *RunSet*, *Security* (the API key is sent and stored nowhere), *Scale* (5,000 items within bounds and the tables do not grow), *Lifecycle* (one run at a time, forward only, a cancelled run is quiet) and *Upgrade* (configuration of an older version still runs).
- **A live test over the two real sites**: `ddev composer verify:live` reads site A, runs the run set `catalog` on site B through the real GraphQL API with the real key, and compares customers, products and prices field by field (and the references between them), and that a second run changes nothing. It changes only what the import itself changes.
- **Mutation testing with Infection** (`ddev composer mutate`): the code of the engine is changed a little (a `>` becomes `>=`, a condition is negated, a call is removed) and the tests that cover the line are run; a change that no test notices is a gap in the tests. This is what answers "would a test notice?". It needs code coverage, which comes from pcov, installed in DDEV but switched off (`.ddev/php/pcov.ini`) and switched on by the scripts. Coverage of all tests of the engine is made once (`ddev composer coverage`, about ten minutes) and Infection uses it, so it runs only the tests that cover a changed line. The forms, hooks, attributes and drush commands are left out: what is tested there is Drupal's machinery or a thin layer.

## What is guaranteed, and what is not
Guaranteed: what is in the list above, for the sources, targets and plugins that exist, against a database like the real one. Not guaranteed: behaviour under real network failure (the source is scripted), real concurrency between processes (workers take turns in one process, and the claim is tested for what it promises), mail, Kubernetes, and what the screens look like (that is the Playwright suite, ADR 0028). The mutation score says how much of the code the tests *notice changes in*; it does not say the code is right.

## Consequences
- A change that breaks a promise is found by `ddev composer verify` in a minute and a half, with the name of the promise in the test.
- The suite is a cost: a change to the engine's behaviour has to be changed in these tests as well, on purpose.
- The first run of the mutation tests is long (hours for all of the engine); it is for after a big change, not for every commit.
