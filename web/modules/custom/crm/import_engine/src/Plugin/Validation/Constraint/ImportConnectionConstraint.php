<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that an import and its connection have everything, and no more.
 *
 * Without a connection the import has all the settings its source needs. With
 * one, the connection has those that belong to a connection and the import the
 * others, none of them twice; the connection is for the same kind of source,
 * and it, not the import, says how to log in.
 */
#[Constraint(
  id: 'ImportConnection',
  label: new TranslatableMarkup('Import and connection fit', [], ['context' => 'Validation']),
)]
class ImportConnectionConstraint extends SymfonyConstraint {

  /**
   * A setting the import needs, from itself or from a connection.
   */
  public string $missing = 'The "@key" setting is needed: give it here, or use a connection that has it.';

  /**
   * A setting that the connection has, given in the import too.
   */
  public string $taken = 'The "@key" setting belongs to the connection: remove it here, or stop using the connection.';

  /**
   * A connection for another kind of source.
   */
  public string $otherSource = 'The connection is for a "@connection" source, and this import reads a "@import" source.';

  /**
   * A connection that lacks a setting.
   */
  public string $connectionLacks = 'The connection has no "@key" setting.';

  /**
   * An import that logs in by itself while the connection does so.
   */
  public string $authentication = 'The connection takes care of the authentication: choose "none" here.';

}
