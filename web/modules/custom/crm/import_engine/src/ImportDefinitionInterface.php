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
   * Returns the target plugin ID and its configuration.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The target: where the items are written.
   */
  public function getTarget(): array;

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
   * Returns the share of known items that may go missing in one run.
   *
   * In percent; when more are missing the sweep does nothing. 0 is no limit.
   */
  public function getDeleteThresholdPercent(): int;

  /**
   * Returns the settings of the circuit breaker of the source.
   *
   * The breaker opens after this many transient failures in a row at the same
   * server; after the cooldown, in seconds, a probe checks whether it is back.
   *
   * @return array{enabled: bool, threshold: int, cooldown: int}
   *   The settings.
   */
  public function getBreaker(): array;

  /**
   * Returns the reporters that are told how a run went.
   *
   * @return list<array{plugin: string, configuration: array<string, mixed>}>
   *   The reporter plugins with their configuration.
   */
  public function getReporters(): array;

  /**
   * Returns the maximum number of attempts per item.
   */
  public function getMaxAttempts(): int;

  /**
   * Returns how the delay between attempts grows.
   */
  public function getBackoff(): BackoffStrategy;

  /**
   * Returns the delay before the first retry of a failed item, in seconds.
   */
  public function getRetryDelay(): int;

  /**
   * Returns after how many repeated pages in a row extraction stops.
   */
  public function getMaxRepeatedPages(): int;

  /**
   * Returns whether items that ran out of attempts are kept for inspection.
   */
  public function isDlqEnabled(): bool;

  /**
   * Returns the worker pool that processes this import's items.
   */
  public function getPool(): string;

}
