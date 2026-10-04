<?php

declare(strict_types=1);

namespace Drupal\import_engine\Process;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Mapper\MapperInterface;
use Drupal\import_engine\Mapper\MapperPluginManager;
use Drupal\import_engine\Path\PathResolver;
use Drupal\import_engine\Target\TargetException;
use Drupal\import_engine\Target\TargetInterface;
use Drupal\import_engine\Target\TargetPluginManager;

/**
 * Builds the plan of an import from its definition.
 */
final class PlanFactory {

  /**
   * Constructs the factory.
   */
  public function __construct(
    private readonly TargetPluginManager $targets,
    private readonly MapperPluginManager $mappers,
    private readonly PathResolver $paths,
  ) {
  }

  /**
   * Builds the plan.
   *
   * @throws \Drupal\import_engine\Process\PlanException
   *   When the definition cannot be turned into a plan.
   */
  public function create(ImportDefinitionInterface $definition): ImportPlan {
    $target = $this->target($definition);
    try {
      $fields = $target->fields();
    }
    catch (TargetException $exception) {
      throw new PlanException($exception->getMessage(), 0, $exception);
    }

    $rows = [];
    foreach ($definition->getMapping() as $index => $row) {
      $number = $index + 1;
      $field = $fields[$row['target_field']] ?? NULL;
      if ($field === NULL) {
        throw new PlanException(sprintf('Mapping row %d: the target has no field "%s".', $number, $row['target_field']));
      }
      $mapper = $this->mappers->createInstance($row['mapper']['plugin'], $row['mapper']['settings']);
      if (!$mapper instanceof MapperInterface) {
        throw new PlanException(sprintf('Mapping row %d: "%s" is not a mapper.', $number, $row['mapper']['plugin']));
      }
      $rows[] = [
        'field' => $field,
        'mapper' => $mapper,
        'paths' => $this->sourcePaths($number, $row['mapper']['plugin'], $row['mapper']['sources']),
      ];
    }
    return new ImportPlan($target, $rows, $this->paths);
  }

  /**
   * Creates the target of an import.
   *
   * @throws \Drupal\import_engine\Process\PlanException
   *   When the target plugin does not exist.
   */
  public function target(ImportDefinitionInterface $definition): TargetInterface {
    $target_definition = $definition->getTarget();
    try {
      $target = $this->targets->createInstance($target_definition['plugin'], $target_definition['configuration']);
    }
    catch (PluginException $exception) {
      throw new PlanException($exception->getMessage(), 0, $exception);
    }
    if (!$target instanceof TargetInterface) {
      throw new PlanException('The target is not a target plugin.');
    }
    return $target;
  }

  /**
   * Checks that a row gives a path for every source the mapper requires.
   *
   * @param int $number
   *   The number of the mapping row, for messages.
   * @param string $plugin
   *   The mapper plugin ID.
   * @param array<string, string> $given
   *   The paths of the row, by source name.
   *
   * @return array<string, string>
   *   The paths of the sources the mapper reads.
   */
  private function sourcePaths(int $number, string $plugin, array $given): array {
    $paths = [];
    foreach ($this->mappers->getDefinition($plugin)['sources'] as $name => $required) {
      if (isset($given[$name])) {
        $paths[$name] = $given[$name];
      }
      elseif ($required) {
        throw new PlanException(sprintf('Mapping row %d: the mapper "%s" needs a path for "%s".', $number, $plugin, $name));
      }
    }
    return $paths;
  }

}
