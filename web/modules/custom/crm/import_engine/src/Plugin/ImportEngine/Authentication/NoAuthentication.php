<?php

declare(strict_types=1);

namespace Drupal\import_engine\Plugin\ImportEngine\Authentication;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\import_engine\Attribute\ImportAuthentication;
use Drupal\import_engine\Authentication\AuthenticationPluginBase;
use Drupal\import_engine\Http\RequestSpec;

/**
 * Sends requests without credentials.
 */
#[ImportAuthentication(
  id: 'none',
  label: new TranslatableMarkup('None'),
  description: new TranslatableMarkup('The source needs no credentials.'),
)]
final class NoAuthentication extends AuthenticationPluginBase {

  /**
   * {@inheritdoc}
   */
  public function apply(RequestSpec $request): RequestSpec {
    return $request;
  }

}
