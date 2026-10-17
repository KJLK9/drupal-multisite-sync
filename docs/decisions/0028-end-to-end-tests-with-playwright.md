# 28. End-to-end tests of the screens with Playwright

Date: 2026-10-17 · Status: accepted

## Context
A change to the wizard made every step but the first fail with a critical error on the cluster. The Kernel tests had built and rendered each step, and all passed: none of them stored the form in the form cache, which is what happens between two requests. Only a real browser going from step to step does that.

## Decision
- **Playwright tests in `e2e/`**, run with `ddev composer e2e`, in the web container of DDEV (the browser is Chromium there; the host, Ubuntu 26.04, is not supported by Playwright) against the local site B.
- **Clean data by prefix**: everything a test makes has an ID that starts with `e2e_`, and the global setup removes all of it before a run. The project's own imports are used (the tests read the `site_a` connection) and never changed. A whole-database reset was considered and left out: it takes minutes and the prefix gives the same repeatability.
- **Login with a one-time link from drush**, so no password is stored.
- **Not part of `ddev composer check`**: they need a running site, and take half a minute. CI is not set up for them yet (it needs DDEV or compose in the pipeline).
- **No screenshots**: comparing images is slow and breaks on every design change; it can come with the visual work.
- The wizard is covered first (create in five steps, edit, check, copy, template) with the help page; list pages, runs and the read-only account are next.

## Consequences
- The first run found a double-escaping error in the plain-language summary (a quote showed as `&quot;`), which no unit test had caught.
- A test fails when the closure that broke the form cache is put back; that is the point of keeping the suite.
