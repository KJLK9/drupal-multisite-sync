# 17. The content model of site B

Date: 2026-10-05 · Status: accepted

## Context
Site B imports the catalog of site A. An import that copies names and shapes one to one shows nothing of what the engine does. Site B should have a model of its own, with the differences a real integration has.

## Decision
- **Three content types of nodes**: Account (from customer), Item (from product) and Agreement (from product price). Site A has its own content entity types; site B uses nodes, which is what an editor team works with, and what the entity target writes without extra code.
- **Differences on purpose**: renamed fields (`customerNumber` is `field_account_number`, `label` is the title), a money value of site A that arrives split in an amount and a currency, `status` that becomes the published flag, a reference by external ID, and text fields with a text format.
- **A reference by external ID** is a reference field filled through the mapping store: an agreement refers to the item and account by their keys at site A. The agreement waits and retries until they exist, so the order of the imports is a convenience and not a requirement.
- **The source ID is kept** in `field_source_id` on every type, as a human can see it. It is not what the engine uses to find an item again: that is the key and the mapping store.
- **The module holds the model only.** The imports are not part of it: they are made in the wizard and exported with the site's configuration. The README describes them, field by field, so they can be made in a few minutes, and a test makes the same three imports in code and runs them against data shaped like site A, so the model and the mapping are proven to fit.
- **A mapper was added for what the source does not give**: an agreement has no title of its own, so a generic "Combine texts" mapper joins a product and a customer. It is part of the engine, since any import may need it.

## Consequences
- The module depends on the import engine, so the imports can be shipped with it later as optional configuration.
- A text format named in an import (Basic HTML) must exist on the site; the engine reports an item whose format does not exist as dead with that reason, which is how this showed up in the test.
- Prices are kept with more decimals than the source sends (the money field stores six), so amounts are compared as numbers.
