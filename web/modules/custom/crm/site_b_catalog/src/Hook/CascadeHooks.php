<?php

declare(strict_types=1);

namespace Drupal\site_b_catalog\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An agreement cannot exist without its account and its item.
 *
 * When an account or an item is deleted, for example because it left the
 * source and the delete policy of its import is "delete", the agreements that
 * refer to it are deleted with it.
 */
final class CascadeHooks {

  /**
   * How many agreements are deleted per query.
   */
  private const BATCH = 100;

  /**
   * Constructs the hooks.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for accounts.
   */
  #[Hook('account_delete')]
  public function accountDeleted(EntityInterface $account): void {
    $this->deleteAgreements('account', $account->id());
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for items.
   */
  #[Hook('item_delete')]
  public function itemDeleted(EntityInterface $item): void {
    $this->deleteAgreements('item', $item->id());
  }

  /**
   * Deletes the agreements that refer to an account or an item.
   *
   * @param string $field
   *   The reference field: account or item.
   * @param int|string|null $id
   *   The ID of the entity that was deleted.
   */
  private function deleteAgreements(string $field, int|string|null $id): void {
    if ($id === NULL) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('agreement');
    do {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition($field, $id)->range(0, self::BATCH)->execute();
      $storage->delete($storage->loadMultiple($ids));
    } while ($ids !== []);
  }

}
