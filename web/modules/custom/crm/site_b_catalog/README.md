# Site B catalog

The content model of site B. It is deliberately not a copy of site A: the
names differ and the shapes differ, so the imports have something to map.

| Site A | Site B | Key at site A |
|--------|--------|---------------|
| Customer | Account (node) | `customerNumber` |
| Product | Item (node) | `id` |
| Product price | Agreement (node) | `id` |

## Content types

- **Account**: title, `field_account_number` (required), `field_notes` (text
  with a format), `field_source_id`.
- **Item**: title, `field_list_price` (money), `field_summary` (text with a
  format), `field_source_id`.
- **Agreement**: title, `field_account` (reference to an account),
  `field_item` (reference to an item), `field_agreed_price` (money),
  `field_source_id`. All three of the first fields are required.

Every type uses the published flag: a customer or product that is switched off
at site A is imported unpublished.

## What the model shows

- Renamed fields: `customerNumber` becomes `field_account_number`, `label`
  becomes the title, `description` becomes notes or a summary.
- A money value is split in an amount and a currency (`basePrice.number` and
  `basePrice.currencyCode`) and goes to one money field.
- `status` becomes the published flag.
- A reference by external ID: an agreement refers to its item and account by
  the keys of the item and the account, through the mapping store, and waits
  and retries when they are not imported yet.
- A text field with a text format.
- A title that no single source value makes: a product and a customer joined.

## The imports

Make these in the wizard (Configuration, System, Import definitions). Run them
in this order: accounts, items, agreements. The source of all three is the
GraphQL source on `http://site-a.ddev.site/graphql/catalog` (plain http: there
is no SSL locally) with the authentication "API key in a header" (header `api-
key`, environment variable `SITE_A_API_KEY`) and paging "Offset and limit" with
the paging values sent in the body: offset parameter `variables.offset`, limit
parameter `variables.limit`, 50 per page.

Use "Try the source" in the wizard to see the paths in the items.

### accounts

Query:

```graphql
query ($limit: Int!, $offset: Int!) {
  customers(limit: $limit, offset: $offset) {
    totalCount
    items { id label customerNumber status description }
  }
}
```

Path of the items `data.customers.items`, of the total
`data.customers.totalCount`. Key: `customerNumber`. Target: node, account.

- Title: Text, `label`.
- `field_account_number`: Text, `customerNumber`.
- `field_notes`: Formatted text, `description`, format Basic HTML.
- `field_source_id`: Text, `id`.
- Status: Yes or no, `status`.

### items

Query:

```graphql
query ($limit: Int!, $offset: Int!) {
  products(limit: $limit, offset: $offset) {
    totalCount
    items { id label status description basePrice { number currencyCode } }
  }
}
```

Path of the items `data.products.items`, of the total
`data.products.totalCount`. Key: `id`. Target: node, item.

- Title: Text, `label`.
- `field_list_price`: Amount and currency, amount `basePrice.number`,
  currency `basePrice.currencyCode`.
- `field_summary`: Formatted text, `description`, format Plain text.
- `field_source_id`: Text, `id`.
- Status: Yes or no, `status`.

### agreements

Query:

```graphql
query ($limit: Int!, $offset: Int!) {
  productPrices(limit: $limit, offset: $offset) {
    totalCount
    items {
      id
      product { id label }
      customer { customerNumber label }
      price { number currencyCode }
    }
  }
}
```

Path of the items `data.productPrices.items`, of the total
`data.productPrices.totalCount`. Key: `id`. Target: node, agreement.

- Title: Combine texts, first `product.label`, second `customer.label`,
  separator " for ".
- `field_item`: Reference, `product.id`, import "items", required.
- `field_account`: Reference, `customer.customerNumber`, import "accounts",
  required.
- `field_agreed_price`: Amount and currency, amount `price.number`, currency
  `price.currencyCode`.
- `field_source_id`: Text, `id`.

## Try it

```bash
ddev drush @ddev.site_b en site_b_catalog import_engine_ui -y
ddev drush @ddev.site_b import:run accounts
ddev drush @ddev.site_b import:run items
ddev drush @ddev.site_b import:run agreements
```

The owner of the imported nodes is the user chosen in the target step. Export
the configuration afterwards with `ddev drush @ddev.site_b cex` and commit it.

## Testing

`CatalogImportTest` makes the three imports in code and runs them against data
shaped like the answers of site A (`tests/fixtures/site_a_catalog.json`): the
fields, the references, a change at the source, an account that leaves, and
agreements that arrive before their accounts.
