<?php

declare(strict_types=1);

namespace Drupal\import_engine\Page;

use Drupal\import_engine\ImportDefinitionInterface;

/**
 * Fingerprints the part of a definition that decides how items are processed.
 *
 * When the mapping, the target or the key change, the same source data has to
 * be processed again, so pages that were remembered as unchanged are void.
 */
final class ProcessingFingerprint {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly PageFingerprint $fingerprints,
  ) {
  }

  /**
   * Returns the fingerprint of a definition, as 32 hex characters.
   */
  public function of(ImportDefinitionInterface $definition): string {
    return $this->fingerprints->fingerprint([
      'key' => $definition->getSourceKey(),
      'target' => [$definition->getTargetEntityType(), $definition->getTargetBundle()],
      'mapping' => $definition->getMapping(),
    ]);
  }

}
