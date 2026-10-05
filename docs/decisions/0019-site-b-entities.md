# 19. Site B has content entities of its own, with real relations

Date: 2026-10-05 · Status: accepted · Replaces ADR 0017

## Context
The first content model of site B was three content types of nodes, with entity reference fields between them. It worked, but it tested relations only weakly: a node reference is a reference to "a node", there was no uniqueness that the database guarantees, nothing happened to an agreement when its account was deleted, and the interface was the generic node interface.

## Decision
- **Three content entity types of their own**: Account, Item and Agreement. They are deliberately unlike the entities of catalog (other names and fields), since the point is that the imports map between different models.
- **Real relations.** An agreement refers to an account and to an item with entity reference base fields, both required, and the type of the target is part of the field. A reference to something that does not exist is refused.
- **Uniqueness is guaranteed twice.** A constraint reports a second agreement for the same account and item before saving, and a unique key in the database makes it impossible however an agreement is saved (`CatalogStorageSchema`). The same goes for the account number. A duplicate in a source ends in the dead letter queue with the reason.
- **A cascade.** Deleting an account or an item deletes its agreements, in batches, so a delete that comes from the sweep of an import (delete policy "delete") leaves nothing that refers to what is gone. Unpublishing does not cascade.
- **Coming back works without help from the engine.** An account that was deleted and returns is a new entity with a new ID. The agreements that refer to it are mapped with that new ID, so their mapped values, and so their hash, differ and they are written again, though their source did not change. This is a reason to keep references in what the hash is made of.
- **The entities describe themselves**: every field says how it is edited and shown, so they need no display configuration, and the module carries no configuration at all. Access is the access handler of `published_access`, with a permission per operation and entity type.
- **The type is the bundle.** The entity target already treats an entity type without bundles that way, which the model is the first user of.
- **A title can be made.** An agreement has no name at the source, so its title is a join of item and account; when it is left empty the entity makes the same.

## Consequences
- The owner of an import must be able to view what the import refers to, including unpublished entities, since the references are validated as that user. In practice: an administrator.
- A site that had the nodes of the first model has to remove them before the entities are installed; the README says how.
- Referential behaviour is part of the entities and not of the engine: another model can choose to block instead of cascade.
