<?php

declare(strict_types=1);

namespace Drupal\import_engine\Process;

use Drupal\import_engine\Path\PathResolver;
use Drupal\import_engine\Target\TargetInterface;

/**
 * What it takes to turn a source item into a written item: the mapping.
 *
 * Built once per import from its definition: the target, and for every mapping
 * row the mapper, the target field and where each named source is read.
 */
final class ImportPlan {

  /**
   * Constructs the plan.
   *
   * @param \Drupal\import_engine\Target\TargetInterface $target
   *   The target.
   * @param list<array{field: \Drupal\import_engine\Target\TargetField, mapper: \Drupal\import_engine\Mapper\MapperInterface, paths: array<string, string>}> $rows
   *   The mapping rows: the target field, the mapper, and the dotted path of
   *   every source name the mapper reads.
   * @param \Drupal\import_engine\Path\PathResolver $paths
   *   The path resolver.
   */
  public function __construct(
    public readonly TargetInterface $target,
    private readonly array $rows,
    private readonly PathResolver $paths,
  ) {
  }

  /**
   * Maps a source item to values for the target.
   *
   * @param array<string, mixed> $item
   *   The source item.
   *
   * @return array<string, mixed>
   *   The value for each mapped target field, in the form the field accepts.
   *
   * @throws \Drupal\import_engine\Mapper\MappingException
   *   When a value cannot be mapped.
   * @throws \Drupal\import_engine\Mapper\DependencyNotReadyException
   *   When a value refers to something that is not imported yet.
   */
  public function map(array $item): array {
    $values = [];
    foreach ($this->rows as $row) {
      $sources = [];
      foreach ($row['paths'] as $name => $path) {
        $sources[$name] = $this->paths->get($item, $path);
      }
      $values[$row['field']->name] = $row['mapper']->map($sources, $row['field']);
    }
    return $values;
  }

}
