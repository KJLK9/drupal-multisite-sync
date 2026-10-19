<?php

declare(strict_types=1);

namespace Drupal\import_engine\Target;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Where the items of an import are written.
 *
 * The first target writes content entities. Others (a file, an external
 * system) can be added without changing the stages that use this interface.
 */
interface TargetInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Returns the fields a field mapping can fill, keyed by name.
   *
   * @return array<string, \Drupal\import_engine\Target\TargetField>
   *   The fields.
   *
   * @throws \Drupal\import_engine\Target\TargetException
   *   When the target is not configured properly, for example when an entity
   *   type does not exist.
   */
  public function fields(): array;

  /**
   * Writes an item.
   *
   * @param array<string, mixed> $values
   *   The values keyed by field name, in the form the field accepts.
   * @param string|null $existingId
   *   The ID of what an earlier run wrote for this item, if any. When it is
   *   gone the item is written as a new one.
   * @param bool $trackChanges
   *   Whether to say, in the result, which fields changed from what to what.
   *   Costs a comparison, so only asked for when somebody wants to know.
   *
   * @throws \Drupal\import_engine\Target\TargetException
   *   When the values are not accepted.
   */
  public function save(array $values, ?string $existingId, bool $trackChanges = FALSE): SaveResult;

  /**
   * Returns whether the target can hide what it wrote without deleting it.
   */
  public function supportsUnpublish(): bool;

  /**
   * Makes something that was written visible again.
   *
   * @return bool
   *   Whether it was changed; FALSE when it is gone or already published.
   */
  public function publish(string $id): bool;

  /**
   * Hides something that was written, without deleting it.
   *
   * @return bool
   *   Whether it was changed; FALSE when it is gone or already hidden.
   */
  public function unpublish(string $id): bool;

  /**
   * Deletes something that was written.
   *
   * @return bool
   *   Whether it was deleted; FALSE when it was already gone.
   */
  public function delete(string $id): bool;

  /**
   * Returns the type of what the target writes, as a save result names it.
   *
   * A mapping remembers it, so a target that is changed to write something
   * else does not use the IDs of what it wrote before.
   */
  public function entityTypeId(): string;

  /**
   * Returns whether something with this ID exists.
   *
   * Without it an import cannot tell that what it wrote was deleted by hand.
   */
  public function exists(string $id): bool;

}
