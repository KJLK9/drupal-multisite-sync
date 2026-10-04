<?php

declare(strict_types=1);

namespace Drupal\import_engine;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Describes one import: where to read, how to map and where to write.
 *
 * Plugin settings are plain arrays here; typed plugin instances are created
 * from them by the plugin managers.
 */
interface ImportDefinitionInterface extends ConfigEntityInterface {

  /**
   * Returns the description.
   */
  public function getDescription(): string;

  /**
   * Returns the source plugin ID and its configuration.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The source.
   */
  public function getSource(): array;

  /**
   * Returns the pagination plugin ID and its configuration.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The pagination.
   */
  public function getPagination(): array;

  /**
   * Returns the authentication plugin ID and its configuration.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The authentication.
   */
  public function getAuthentication(): array;

  /**
   * Returns the key paths.
   *
   * These are the dotted paths whose values identify a source item.
   *
   * @return list<string>
   *   One or more paths; the values together are the key of an item.
   */
  public function getSourceKey(): array;

  /**
   * Returns the target entity type ID.
   */
  public function getTargetEntityType(): string;

  /**
   * Returns the target bundle.
   */
  public function getTargetBundle(): string;

  /**
   * Returns the field mapping.
   *
   * @return list<array{target_field: string, mapper: array{plugin: string, sources: array<string, string>, settings: array<string, mixed>}}>
   *   One row per target field.
   */
  public function getMapping(): array;

  /**
   * Returns what to do with entities whose source item is gone.
   */
  public function getDeletePolicy(): DeletePolicy;

  /**
   * Returns the maximum number of attempts per item.
   */
  public function getMaxAttempts(): int;

  /**
   * Returns how the delay between attempts grows.
   */
  public function getBackoff(): BackoffStrategy;

  /**
   * Returns whether items that ran out of attempts are kept for inspection.
   */
  public function isDlqEnabled(): bool;

  /**
   * Returns the worker pool that processes this import's items.
   */
  public function getPool(): string;

}
