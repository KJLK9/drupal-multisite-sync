<?php

declare(strict_types=1);

namespace Drupal\Tests\site_b_catalog\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\site_b_catalog\Entity\Account;
use Drupal\site_b_catalog\Entity\Agreement;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the relations between accounts, items and agreements.
 */
#[Group('site_b_catalog')]
#[RunTestsInSeparateProcesses]
class RelationsTest extends CatalogTestBase {

  /**
   * Returns the messages of the violations, by property path.
   *
   * @return array<string, string>
   *   The messages.
   */
  protected function violations(FieldableEntityInterface $entity): array {
    $messages = [];
    foreach ($entity->validate() as $violation) {
      $messages[$violation->getPropertyPath()] = strip_tags((string) $violation->getMessage());
    }
    return $messages;
  }

  /**
   * An agreement without a title is called after its item and account.
   */
  public function testAgreementIsNamedAfterWhatItAgrees(): void {
    $agreement = $this->agreement($this->account('C-1', ['name' => 'Acme']), $this->item('Widget'));

    $this->assertSame('Widget for Acme', $agreement->label());

    $named = Agreement::create([
      'title' => 'Special deal',
      'account' => $agreement->get('account')->target_id,
      'item' => $this->item('Gadget')->id(),
      'price' => ['number' => '1.00', 'currency_code' => 'EUR'],
    ]);
    $named->save();
    $this->assertSame('Special deal', $named->label());
  }

  /**
   * An agreement needs an account and an item that exist.
   */
  public function testReferencesAreRequiredAndMustExist(): void {
    $account = $this->account('C-1');
    $item = $this->item('Widget');
    $price = ['number' => '1.00', 'currency_code' => 'EUR'];

    $none = Agreement::create(['price' => $price]);
    $this->assertEqualsCanonicalizing(['account', 'item'], array_keys($this->violations($none)));

    $dangling = Agreement::create(['account' => $account->id(), 'item' => 9999, 'price' => $price]);
    $this->assertSame(['item.0.target_id'], array_keys($this->violations($dangling)));

    $nowhere = Agreement::create(['account' => 9999, 'item' => $item->id(), 'price' => $price]);
    $this->assertSame(['account.0.target_id'], array_keys($this->violations($nowhere)));

    $this->assertSame([
      'price',
    ], array_keys($this->violations(Agreement::create(['account' => $account->id(), 'item' => $item->id()]))));
  }

  /**
   * An item cannot be used where an account is meant.
   */
  public function testReferencesPointAtTheRightType(): void {
    $account = $this->account('C-1');
    $item = $this->item('Widget');
    // The IDs overlap (both are 1): only the type tells them apart.
    $this->assertSame($account->id(), $item->id());

    $agreement = Agreement::create([
      'account' => $account->id(),
      'item' => $account->id(),
      'price' => ['number' => '1', 'currency_code' => 'EUR'],
    ]);
    $this->assertSame([], $this->violations($agreement), 'The ID exists as an item as well, so this is valid.');
    $other = $this->item('Gadget');
    $this->assertNotSame($other->id(), $account->id());
    $missing = Agreement::create([
      'account' => $other->id(),
      'item' => $item->id(),
      'price' => ['number' => '1', 'currency_code' => 'EUR'],
    ]);
    $this->assertSame(['account.0.target_id'], array_keys($this->violations($missing)), 'There is no account 2, however many items there are.');
  }

  /**
   * An account number is unique: reported by validation, kept by the database.
   */
  public function testAccountNumberIsUnique(): void {
    $this->account('C-1');
    $second = Account::create(['name' => 'Other', 'number' => 'C-1']);

    $this->assertSame(['number'], array_keys($this->violations($second)));

    // Saved without validation, the database still refuses.
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage("Duplicate entry 'C-1' for key 'account__number'");
    $second->save();
  }

  /**
   * An account has one agreement per item: validation and the database agree.
   */
  public function testOneAgreementPerAccountAndItem(): void {
    $account = $this->account('C-1');
    $item = $this->item('Widget');
    $this->agreement($account, $item);
    $price = ['number' => '9.00', 'currency_code' => 'EUR'];
    $duplicate = Agreement::create(['account' => $account->id(), 'item' => $item->id(), 'price' => $price]);

    $this->assertSame(['item' => 'This account already has an agreement for this item.'], $this->violations($duplicate));
    // Another item, or another account, is fine.
    $this->assertSame([], $this->violations(Agreement::create([
      'account' => $account->id(),
      'item' => $this->item('Gadget')->id(),
      'price' => $price,
    ])));
    $this->assertSame([], $this->violations(Agreement::create([
      'account' => $this->account('C-2')->id(),
      'item' => $item->id(),
      'price' => $price,
    ])));

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage("for key 'agreement__account_item'");
    $duplicate->save();
  }

  /**
   * Changing an agreement is not a duplicate of itself.
   */
  public function testAgreementCanBeChanged(): void {
    $agreement = $this->agreement($this->account('C-1'), $this->item('Widget'));
    $agreement->set('price', ['number' => '7.00', 'currency_code' => 'EUR']);

    $this->assertSame([], $this->violations($agreement));
    $agreement->save();
  }

  /**
   * Deleting an account deletes its agreements, and only those.
   */
  public function testDeletingAnAccountDeletesItsAgreements(): void {
    $acme = $this->account('C-1');
    $globex = $this->account('C-2');
    $widget = $this->item('Widget');
    $gadget = $this->item('Gadget');
    $this->agreement($acme, $widget);
    $this->agreement($acme, $gadget);
    $kept = $this->agreement($globex, $widget);

    $acme->delete();

    $this->assertSame(1, $this->total('agreement'));
    $this->assertNotNull(Agreement::load($kept->id()));
    $this->assertSame(2, $this->total('item'), 'The items stay.');
  }

  /**
   * Deleting an item deletes its agreements, and only those.
   */
  public function testDeletingAnItemDeletesItsAgreements(): void {
    $acme = $this->account('C-1');
    $widget = $this->item('Widget');
    $gadget = $this->item('Gadget');
    $this->agreement($acme, $widget);
    $kept = $this->agreement($acme, $gadget);

    $widget->delete();

    $this->assertSame(1, $this->total('agreement'));
    $this->assertNotNull(Agreement::load($kept->id()));
    $this->assertSame(1, $this->total('account'), 'The account stays.');
  }

  /**
   * More agreements than one batch are all deleted.
   */
  public function testDeletingCascadesBeyondOneBatch(): void {
    $acme = $this->account('C-1');
    $other = $this->account('C-2');
    for ($i = 1; $i <= 105; $i++) {
      $item = $this->item("Item $i");
      $this->agreement($acme, $item);
      if ($i <= 3) {
        $this->agreement($other, $item);
      }
    }
    $this->assertSame(108, $this->total('agreement'));

    $acme->delete();

    $this->assertSame(3, $this->total('agreement'));
  }

  /**
   * Unpublishing an account leaves its agreements alone.
   */
  public function testUnpublishingDoesNotCascade(): void {
    $acme = $this->account('C-1');
    $this->agreement($acme, $this->item('Widget'));

    $acme->setUnpublished()->save();

    $this->assertSame(1, $this->total('agreement'));
  }

  /**
   * Who may see what: the permission, and the published flag.
   */
  public function testAccess(): void {
    $published = $this->account('C-1');
    $hidden = $this->account('C-2', ['status' => 0]);
    $viewer = $this->createUserWith(['view account']);
    $nobody = $this->createUserWith([]);
    $admin = $this->createUserWith(['administer account']);

    $this->assertTrue($published->access('view', $viewer));
    $this->assertFalse($published->access('view', $nobody));
    $this->assertFalse($hidden->access('view', $viewer), 'Unpublished is for administrators.');
    $this->assertTrue($hidden->access('view', $admin));
    $this->assertFalse($published->access('update', $viewer));
    $this->assertTrue($published->access('update', $this->createUserWith(['edit account'])));
    $this->assertFalse($published->access('delete', $viewer));
    $this->assertTrue($this->container->get('entity_type.manager')->getAccessControlHandler('account')->createAccess(NULL, $this->createUserWith(['create account'])));

    // Lists agree with access.
    $this->container->get('current_user')->setAccount($viewer);
    $ids = $this->container->get('entity_type.manager')->getStorage('account')->getQuery()->accessCheck(TRUE)->execute();
    $this->assertSame([(string) $published->id() => (string) $published->id()], $ids);
  }

  /**
   * Creates a user with permissions, through a role.
   *
   * @param list<string> $permissions
   *   The permissions.
   */
  protected function createUserWith(array $permissions): User {
    static $count = 0;
    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->create([
      'id' => 'role' . ++$count,
      'label' => 'Role',
    ]);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $user = User::create(['name' => 'user' . $count, 'status' => 1, 'roles' => [$role->id()]]);
    $user->save();
    return $user;
  }

}
