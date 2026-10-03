<?php

declare(strict_types=1);

namespace Drupal\customers\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\user\UserInterface;

/**
 * Hook implementations for the customer entity type.
 */
final class CustomerHooks {

  /**
   * Constructs a CustomerHooks object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Implements hook_theme().
   *
   * @return array<string, array<string, string>>
   *   The theme hook definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'customer' => ['render element' => 'elements'],
    ];
  }

  /**
   * Prepares variables for customer templates.
   *
   * Default template: customer.html.twig.
   *
   * @param array<string, mixed> $variables
   *   An associative array containing:
   *   - elements: An associative array containing the customer information and
   *     any fields attached to the entity.
   *   - attributes: HTML attributes for the containing element.
   */
  #[Hook('preprocess_customer')]
  public function preprocessCustomer(array &$variables): void {
    $variables['view_mode'] = $variables['elements']['#view_mode'];
    foreach (Element::children($variables['elements']) as $key) {
      $variables['content'][$key] = $variables['elements'][$key];
    }
  }

  /**
   * Implements hook_user_cancel().
   *
   * @param array<string, mixed> $edit
   *   The user cancellation form values.
   * @param \Drupal\user\UserInterface $account
   *   The account being cancelled.
   * @param string $method
   *   The account cancellation method.
   */
  #[Hook('user_cancel')]
  public function userCancel(array $edit, UserInterface $account, string $method): void {
    $storage = $this->entityTypeManager->getStorage('customer');
    switch ($method) {
      case 'user_cancel_block_unpublish':
        // Unpublish customers.
        $ids = $storage->getQuery()
          ->condition('uid', $account->id())
          ->condition('status', 1)
          ->accessCheck(FALSE)
          ->execute();
        foreach ($storage->loadMultiple($ids) as $customer) {
          /** @var \Drupal\customers\CustomerInterface $customer */
          $customer->set('status', FALSE)->save();
        }
        break;

      case 'user_cancel_reassign':
        // Anonymize customers.
        $ids = $storage->getQuery()
          ->condition('uid', $account->id())
          ->accessCheck(FALSE)
          ->execute();
        foreach ($storage->loadMultiple($ids) as $customer) {
          /** @var \Drupal\customers\CustomerInterface $customer */
          $customer->setOwnerId(0)->save();
        }
        break;
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_predelete() for user entities.
   *
   * Deletes the customers that belong to the account.
   */
  #[Hook('user_predelete')]
  public function userPredelete(UserInterface $account): void {
    $storage = $this->entityTypeManager->getStorage('customer');
    $ids = $storage->getQuery()
      ->condition('uid', $account->id())
      ->accessCheck(FALSE)
      ->execute();
    $storage->delete($storage->loadMultiple($ids));
  }

}
