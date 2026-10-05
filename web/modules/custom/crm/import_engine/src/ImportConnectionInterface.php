<?php

declare(strict_types=1);

namespace Drupal\import_engine;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A place to read from and how to log in there, shared by imports.
 *
 * Every import of a catalog reads from the same server with the same key; only
 * what it asks for differs. A connection holds the first part, once. An import
 * that uses it leaves those settings to the connection, so a change of the
 * address or of the key is made in one place.
 */
interface ImportConnectionInterface extends ConfigEntityInterface {

  /**
   * Returns the description.
   */
  public function getDescription(): string;

  /**
   * Returns the source plugin and the settings that belong to a connection.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The plugin ID and the settings the plugin names as those of a connection.
   */
  public function getSource(): array;

  /**
   * Returns the authentication plugin and its configuration.
   *
   * @return array{plugin: string, configuration: array<string, mixed>}
   *   The plugin ID and its configuration.
   */
  public function getAuthentication(): array;

}
