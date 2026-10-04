<?php

declare(strict_types=1);

namespace Drupal\import_engine\Mapper;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\import_engine\Target\TargetField;

/**
 * Turns named source values into the value of one target field.
 *
 * Which mapper fits a field follows from the type of the field. The sources a
 * mapper reads are named in its plugin definition, so a mapping row can say
 * for each name where in the source item the value is.
 */
interface MapperInterface extends PluginInspectionInterface, ConfigurableInterface {

  /**
   * Maps source values to a field value.
   *
   * @param array<string, mixed> $sources
   *   The source values by their names; NULL for a value the item lacks.
   * @param \Drupal\import_engine\Target\TargetField $field
   *   The target field.
   *
   * @return mixed
   *   The value in the form the field accepts; NULL clears the field.
   *
   * @throws \Drupal\import_engine\Mapper\MappingException
   *   When a value cannot be mapped.
   * @throws \Drupal\import_engine\Mapper\DependencyNotReadyException
   *   When a value refers to something that is not imported yet.
   */
  public function map(array $sources, TargetField $field): mixed;

}
