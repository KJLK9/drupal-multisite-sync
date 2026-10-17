# End-to-end tests

Playwright tests of the screens of site B, in a real browser, so that the whole
chain is exercised: the form cache, AJAX, the pages between the steps.

```bash
ddev composer e2e                          # all
ddev composer e2e -- tests/help.spec.ts    # one file
```

They run **inside DDEV** (the browser is Chromium in the web container) against
`https://site-b.ddev.site`; `E2E_BASE_URL` changes that. They are not part of
`ddev composer check`: they need the site running and take about half a minute.

## Clean data

`global-setup.ts` rebuilds the caches, removes every import, connection and run
set whose ID starts with `e2e_` (what the tests make, and what a failed run
left), and logs in as user 1 with a one-time link from drush, so there is no
password in the repository. The imports and connections of the project itself
are used (the tests read the `site_a` connection) and never changed or removed.

## The tests

- `tests/wizard.spec.ts` runs in order and shares one import: it is made in five
  steps (source from a connection, paging, key and target, mapping with sample
  values and preview, summary; it must start switched off), edited step by
  step, given a check, copied, and made a template.
- `tests/help.spec.ts`: the help page, and the info icons.

Add a file per area (list pages, runs, the read-only account). A test that
needs data makes it with an ID that starts with `e2e_`.
