<?php

declare(strict_types=1);

namespace Drupal\Tests\site_b_catalog\Kernel;

use Drupal\Core\Utility\UpdateException;
use Drupal\site_b_catalog\Entity\Account;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update that gives an older site the unique keys of the model.
 */
#[Group('site_b_catalog')]
#[RunTestsInSeparateProcesses]
class UpdateTest extends CatalogTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/site_b_catalog.install';
  }

  /**
   * A site without the keys gets them, and the database then guarantees them.
   */
  public function testUpdateAddsTheUniqueKeys(): void {
    $schema = $this->container->get('database')->schema();
    $schema->dropUniqueKey('account', 'account__number');
    $schema->dropUniqueKey('agreement', 'agreement__account_item');
    $this->assertFalse($schema->indexExists('account', 'account__number'));

    $message = site_b_catalog_update_10001();

    $this->assertTrue($schema->indexExists('account', 'account__number'));
    $this->assertTrue($schema->indexExists('agreement', 'agreement__account_item'));
    $this->assertStringContainsString('account__number', $message);
    $this->assertStringContainsString('agreement__account_item', $message);
  }

  /**
   * Run again, or on a site that has the keys, it changes nothing.
   */
  public function testUpdateIsRepeatable(): void {
    $this->assertSame('The unique keys were there already.', site_b_catalog_update_10001());
    $this->assertSame('The unique keys were there already.', site_b_catalog_update_10001());
  }

  /**
   * Duplicates in the data stop the update, and the message says what to do.
   */
  public function testDuplicatesStopTheUpdate(): void {
    $schema = $this->container->get('database')->schema();
    $schema->dropUniqueKey('account', 'account__number');
    // Saving does not validate; with the key gone nothing else stops the copy.
    foreach (['Acme', 'Globex'] as $name) {
      Account::create(['name' => $name, 'number' => 'C-1'])->save();
    }

    try {
      site_b_catalog_update_10001();
      $this->fail('Expected an UpdateException.');
    }
    catch (UpdateException $exception) {
      $this->assertStringContainsString('account__number', $exception->getMessage());
      $this->assertStringContainsString('duplicates', $exception->getMessage());
    }
    $this->assertFalse($schema->indexExists('account', 'account__number'));
  }

}
