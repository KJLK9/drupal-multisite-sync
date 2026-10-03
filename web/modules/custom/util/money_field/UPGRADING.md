# Upgrading

## Removing a supported currency

`CurrencyCode` cases are enforced by a `Choice` constraint on
`MoneyFieldItem::getConstraints()`. If you remove a case while existing
entities still store that currency code, those entities will fail
validation the next time they are saved — even for unrelated edits.

Before removing a `CurrencyCode` case:

1. Write a `hook_update_N()` that queries all entities with
   `money_field`-type fields for the currency code being removed.
2. Decide how to handle each match: convert to another currency (requires
   an actual exchange rate, not a guess), or flag the record for manual
   review. Do not silently default to an arbitrary currency.
3. Run the update and confirm no remaining entities reference the removed
   code, before deploying the code change that removes the enum case.
