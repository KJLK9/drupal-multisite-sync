# Site B catalog

The content model of site B: three content entity types of its own. They are
deliberately not a copy of those of site A (customer, product, product price):
the names differ and the shapes differ, so the imports have something to map.

| Site A | Site B | Key at site A |
|--------|--------|---------------|
| Customer | Account | `customerNumber` |
| Product | Item | `id` |
| Product price | Agreement | `id` |

## The entities

- **Account** (`/admin/site-b/accounts`): `name`, `number` (unique),
  `notes` (text with a format), `source_id`, published flag.
- **Item** (`/admin/site-b/items`): `title`, `sku`, `list_price` (money),
  `summary` (text with a format), `source_id`, published flag.
- **Agreement** (`/admin/site-b/agreements`): `account` and `item` (real
  entity references, both required), `price` (money, required), `title`,
  `source_id`, published flag.

They have no bundles. Each has its own list, forms and permissions
(`view account`, `edit account`, ... and `administer account`), and uses the
access handler of `published_access`: unpublished entities are only for
administrators.

## The relations

- An agreement refers to an account and to an item. A reference to something
  that does not exist is refused by validation.
- **One agreement per account and item.** The constraint reports it
  (`This account already has an agreement for this item.`) and a unique key in
  the database guarantees it. The account number is unique in the same way.
- **Deleting an account or an item deletes its agreements** (a cascade), also
  when it happens because it left the source and the delete policy of its
  import is "delete". Unpublishing leaves the agreements alone.
- An agreement without a title is called after what it agrees: "Widget for
  Acme BV".

## What the model shows

- Renamed fields: `customerNumber` becomes `number`, `label` becomes `name`.
- A money value is split in an amount and a currency (`basePrice.number`
  and `basePrice.currencyCode`) and goes to one money field.
- `status` becomes the published flag.
- A reference by external ID: an agreement refers to its item and account by
  the keys they have at site A, through the mapping store, and waits and
  retries when they are not imported yet.
- A text field with a text format.
- A title that no single source value makes: a product and a customer joined.

## The imports

These imports are exported in `config/site_b/sync`, with the connection
`site_a` and the run set `catalog` (`ddev drush @ddev.site_b import:run-set
catalog`), so `cim` on a fresh site B creates them. What follows is what they
contain, and what to enter if you make them in the wizard (Configuration,
System, Import definitions). Run them in this order: accounts, items,
agreements. The source of all three is the GraphQL source on
`http://site-a.ddev.site/graphql/catalog` (plain http: there is no SSL locally)
with the authentication "API key in a header" (header `api-key`, environment
variable `SITE_A_API_KEY`) and paging "Offset and limit" with the paging values
sent in the body: offset parameter `variables.offset`, limit parameter
`variables.limit`, 50 per page. The target is the entity type itself (Account,
Item, Agreement). The owner of what is written must be allowed to view accounts
and items, including unpublished ones: an administrator.

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
`data.customers.totalCount`. Key: `customerNumber`. Target: Account.

- `name`: Text, `label`.
- `number`: Text, `customerNumber`.
- `notes`: Formatted text, `description`, format Basic HTML.
- `source_id`: Text, `id`.
- `status`: Yes or no, `status`.

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
`data.products.totalCount`. Key: `id`. Target: Item.

- `title`: Text, `label`.
- `list_price`: Amount and currency, amount `basePrice.number`, currency
  `basePrice.currencyCode`.
- `summary`: Formatted text, `description`, format Plain text.
- `source_id`: Text, `id`.
- `status`: Yes or no, `status`.

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
`data.productPrices.totalCount`. Key: `id`. Target: Agreement.

- `title`: Combine texts, first `product.label`, second `customer.label`,
  separator " for ".
- `item`: Reference, `product.id`, import "items", required.
- `account`: Reference, `customer.customerNumber`, import "accounts",
  required.
- `price`: Amount and currency, amount `price.number`, currency
  `price.currencyCode`.
- `source_id`: Text, `id`.

## Try it

```bash
ddev drush @ddev.site_b en site_b_catalog import_engine_ui -y
ddev drush @ddev.site_b import:run accounts
ddev drush @ddev.site_b import:run items
ddev drush @ddev.site_b import:run agreements
```

Export the configuration afterwards with `ddev drush @ddev.site_b cex` and
commit it.

## Changing from the node model

This module first had nodes (content types account, item and agreement). If
site B has them, remove them before the entities of this module are
installed: delete the nodes, then the content types, then install the module
again.

```bash
ddev drush @ddev.site_b entity:delete node --bundle=account -y
ddev drush @ddev.site_b entity:delete node --bundle=item -y
ddev drush @ddev.site_b entity:delete node --bundle=agreement -y
ddev drush @ddev.site_b entity:delete node_type account,item,agreement -y
ddev drush @ddev.site_b pmu site_b_catalog -y
ddev drush @ddev.site_b en site_b_catalog -y
```

Imports that wrote nodes have to be pointed at the new entity types and
mapped again.

## Testing

`RelationsTest` tests what the model guarantees: references, uniqueness in
validation and in the database, the cascade (also beyond one batch), access.
`CatalogImportTest` makes the three imports in code and runs them against data
shaped like the answers of site A (`tests/fixtures/site_a_catalog.json`): the
fields, the references, a change at the source, an account that is unpublished
or deleted and comes back, a second price for the same account and item,
and agreements that arrive before their accounts.
