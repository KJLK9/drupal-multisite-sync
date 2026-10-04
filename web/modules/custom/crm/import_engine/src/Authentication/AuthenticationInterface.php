<?php

declare(strict_types=1);

namespace Drupal\import_engine\Authentication;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\import_engine\Http\RequestSpec;

/**
 * Makes an outgoing request prove who sends it.
 */
interface AuthenticationInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Returns a copy of the request with the credentials applied.
   *
   * @throws \Drupal\import_engine\Secret\MissingSecretException
   *   When a secret the plugin needs is not available.
   */
  public function apply(RequestSpec $request): RequestSpec;

}
