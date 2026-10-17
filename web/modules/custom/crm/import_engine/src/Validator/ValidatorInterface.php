<?php

declare(strict_types=1);

namespace Drupal\import_engine\Validator;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Checks the value of a mapping row.
 *
 * Only the main value of a row is checked, which is a single text or number
 * (the text of a formatted text, not its format), and an empty value is
 * accepted unless the validator is about emptiness (validatesEmpty): whether a
 * value has to be there is a question of its own.
 */
interface ValidatorInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Checks a value.
   *
   * @param string|int|float $value
   *   The value, not empty (see validatesEmpty()).
   */
  public function validate(string|int|float $value): ValidationOutcome;

  /**
   * Tells whether an empty value is for this validator to judge.
   */
  public function validatesEmpty(): bool;

}
