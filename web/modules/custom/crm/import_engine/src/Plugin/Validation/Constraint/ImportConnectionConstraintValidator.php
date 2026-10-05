<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\import_engine\Connection\ConnectionResolver;
use Drupal\import_engine\ImportConnectionInterface;
use Drupal\import_engine\ImportDefinitionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates ImportConnectionConstraint.
 */
final class ImportConnectionConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConnectionResolver $resolver,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'), $container->get('import_engine.connection_resolver'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ImportConnectionConstraint) {
      throw new UnexpectedTypeException($constraint, ImportConnectionConstraint::class);
    }
    if (!$value instanceof ImportDefinitionInterface) {
      return;
    }

    $source = $value->getSource();
    $plugin = $source['plugin'];
    $own = $source['configuration'];
    $required = $this->resolver->requiredKeys($plugin);
    $owned = $this->resolver->connectionKeys($plugin);
    $id = $value->getConnection();

    if ($id === NULL) {
      foreach ($required as $key) {
        $this->requireKey($constraint, $own, $key);
      }
      return;
    }

    $connection = $this->entityTypeManager->getStorage('import_connection')->load($id);
    // A connection that does not exist is reported by the field itself.
    if (!$connection instanceof ImportConnectionInterface) {
      return;
    }
    $theirs = $connection->getSource();
    if ($theirs['plugin'] !== $plugin) {
      $arguments = ['@connection' => $theirs['plugin'], '@import' => $plugin];
      $this->context->buildViolation($constraint->otherSource, $arguments)
        ->atPath('connection')
        ->addViolation();
      return;
    }
    foreach ($owned as $key) {
      if (array_key_exists($key, $own)) {
        $this->context->buildViolation($constraint->taken, ['@key' => $key])->atPath('source.configuration.' . $key)->addViolation();
      }
    }
    foreach ($required as $key) {
      if (in_array($key, $owned, TRUE)) {
        if (($theirs['configuration'][$key] ?? '') === '') {
          $this->context->buildViolation($constraint->connectionLacks, ['@key' => $key])->atPath('connection')->addViolation();
        }
      }
      else {
        $this->requireKey($constraint, $own, $key);
      }
    }
    if ($value->getAuthentication()['plugin'] !== 'none') {
      $this->context->buildViolation($constraint->authentication)->atPath('authentication.plugin')->addViolation();
    }
  }

  /**
   * Reports a setting the import must have of its own and does not.
   *
   * @param \Drupal\import_engine\Plugin\Validation\Constraint\ImportConnectionConstraint $constraint
   *   The constraint.
   * @param array<string, mixed> $configuration
   *   The settings of the import.
   * @param string $key
   *   The setting.
   */
  private function requireKey(ImportConnectionConstraint $constraint, array $configuration, string $key): void {
    $given = $configuration[$key] ?? '';
    if ($given === '' || $given === []) {
      $this->context->buildViolation($constraint->missing, ['@key' => $key])->atPath('source.configuration.' . $key)->addViolation();
    }
  }

}
