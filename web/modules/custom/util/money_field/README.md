# Money Field

Provides a `money_field` field type for storing a monetary amount together
with a currency code, modeled after the pattern used by Drupal Commerce's
`commerce_price` field.

## Why not use commerce_price?

This module exists as a standalone, dependency-free field type for use
outside of a full Commerce installation. It's useful when you only need a
price-like value (amount + currency) on an entity that has nothing to do
with an actual storefront.

## Structure

- **FieldType** (`MoneyFieldItem`): two columns, `number` (numeric,
  precision 19/scale 6) and `currency_code` (varchar 3). Validated via a
  `Choice` constraint against `CurrencyCode` enum cases.
- **FieldWidget** (`MoneyFieldWidget`): a number input for the amount and a
  select list for currency, built from `CurrencyCode::options()`.
- **FieldFormatter** (`MoneyFieldFormatter`): renders amount + currency code
  as plain text.
- **CurrencyCode** enum: the fixed set of supported currencies.

## Usage

Add a field of type "Money field" to any entity bundle via the normal
Field UI, or define it as a base field using
`BaseFieldDefinition::create('money_field')`.

## Extending supported currencies

Adding a new `CurrencyCode` case is safe at any time. Removing one is a
breaking change: see UPGRADING.md before doing so.
