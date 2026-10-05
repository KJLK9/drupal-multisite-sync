<?php

declare(strict_types=1);

namespace Drupal\Tests\site_b_catalog\Kernel;

use Drupal\import_engine\Drive\DriveResult;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Storage\ItemState;
use Drupal\site_b_catalog\Entity\Account;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the imports that fill the content model, with data shaped like site A.
 *
 * The three imports are the ones to make in the wizard; the README of the
 * module shows them as lists. Here they are made in code, so the model and the
 * mapping are proven to work together, relations included.
 */
#[Group('site_b_catalog')]
#[RunTestsInSeparateProcesses]
class CatalogImportTest extends CatalogTestBase {

  /**
   * The entity type each import writes.
   */
  private const TYPES = ['accounts' => 'account', 'items' => 'item', 'agreements' => 'agreement'];

  /**
   * The data of site A, as its GraphQL API returns it.
   *
   * @var array<string, list<array<string, mixed>>>
   */
  protected array $siteA = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->siteA = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/site_a_catalog.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Saves one of the imports.
   *
   * @param string $id
   *   The ID of the import.
   * @param string $key
   *   The path of the key of an item.
   * @param list<array<string, mixed>> $mapping
   *   The mapping rows.
   * @param array<string, mixed> $values
   *   Other values of the definition.
   */
  protected function saveImport(string $id, string $key, array $mapping, array $values = []): ImportDefinition {
    $type = self::TYPES[$id];
    $definition = ImportDefinition::create($values + [
      'id' => $id,
      'label' => ucfirst($id),
      'source' => [
        'plugin' => 'graphql',
        'configuration' => [
          'url' => 'http://site-a.test/graphql/catalog',
          'query' => '{ x }',
          'variables' => '',
          'headers' => [],
          'items_path' => 'data',
          'timeout' => 30,
        ],
      ],
      'authentication' => [
        'plugin' => 'api_key_header',
        'configuration' => ['header' => 'api-key', 'env_var' => 'SITE_A_API_KEY'],
      ],
      'pagination' => ['plugin' => 'none', 'configuration' => []],
      'source_key' => [$key],
      'target' => ['plugin' => 'entity', 'configuration' => ['entity_type' => $type, 'bundle' => $type, 'owner' => 1]],
      'mapping' => $mapping,
      'delete_policy' => 'unpublish',
    ]);
    $this->assertCount(0, $definition->getTypedData()->validate(), 'The import is valid for its schema.');
    $definition->save();
    return $definition;
  }

  /**
   * Builds a mapping row.
   *
   * @param string $field
   *   The field of the target.
   * @param string $mapper
   *   The mapper plugin.
   * @param array<string, string> $sources
   *   The paths of the sources of the mapper.
   * @param array<string, mixed> $settings
   *   The settings of the mapper.
   *
   * @return array<string, mixed>
   *   The row.
   */
  protected static function row(string $field, string $mapper, array $sources, array $settings = []): array {
    return ['target_field' => $field, 'mapper' => ['plugin' => $mapper, 'sources' => $sources, 'settings' => $settings]];
  }

  /**
   * Makes the three imports.
   *
   * @param array<string, array<string, mixed>> $values
   *   Values that replace those of the definitions, by import.
   */
  protected function makeImports(array $values = []): void {
    $this->saveImport('accounts', 'customerNumber', [
      self::row('name', 'string', ['value' => 'label']),
      self::row('number', 'string', ['value' => 'customerNumber']),
      self::row('notes', 'text', ['value' => 'description'], ['format' => 'basic_html']),
      self::row('source_id', 'string', ['value' => 'id']),
      self::row('status', 'boolean', ['value' => 'status']),
    ], $values['accounts'] ?? []);
    $this->saveImport('items', 'id', [
      self::row('title', 'string', ['value' => 'label']),
      self::row('list_price', 'money', ['amount' => 'basePrice.number', 'currency' => 'basePrice.currencyCode']),
      self::row('summary', 'text', ['value' => 'description'], ['format' => 'plain_text']),
      self::row('source_id', 'string', ['value' => 'id']),
      self::row('status', 'boolean', ['value' => 'status']),
    ], $values['items'] ?? []);
    $this->saveImport('agreements', 'id', [
      self::row('title', 'join', [
        'first' => 'product.label',
        'second' => 'customer.label',
      ], ['separator' => ' for ', 'skip_empty' => TRUE]),
      self::row('item', 'reference', ['id' => 'product.id'], ['definition' => 'items', 'required' => TRUE]),
      self::row('account', 'reference', [
        'id' => 'customer.customerNumber',
      ], ['definition' => 'accounts', 'required' => TRUE]),
      self::row('price', 'money', ['amount' => 'price.number', 'currency' => 'price.currencyCode']),
      self::row('source_id', 'string', ['value' => 'id']),
    ], $values['agreements'] ?? []);
  }

  /**
   * Starts a run of an import and drives it as far as it goes.
   *
   * @param string $id
   *   The ID of the import.
   * @param list<array<string, mixed>> $items
   *   The items of the source, in one page.
   */
  protected function drive(string $id, array $items): DriveResult {
    $definition = ImportDefinition::load($id);
    $this->assertNotNull($definition);
    $run = $this->container->get('import_engine.run_starter')->start($definition, Trigger::Drush);
    return $this->container->get('import_engine.run_driver')->drive($run, $definition, new RunBudget(), 'test', 50, new FakeSource([$items]));
  }

  /**
   * Runs an import to the end; it must complete.
   *
   * @param string $id
   *   The ID of the import.
   * @param list<array<string, mixed>> $items
   *   The items of the source, in one page.
   */
  protected function runImport(string $id, array $items): RunStatus {
    $result = $this->drive($id, $items);
    $this->assertSame(DriveStatus::Finished, $result->status, (string) $result->message);
    return $result->runStatus;
  }

  /**
   * Returns the entity an item of an import became, if there is one.
   */
  protected function entityOf(string $import, string $key): ?FieldableEntityInterface {
    $record = $this->container->get('import_engine.mapping_store')->find($import, '["' . $key . '"]');
    $this->assertNotNull($record, "$import $key");
    $this->assertNotNull($record->targetId);
    $entity = $this->container->get('entity_type.manager')->getStorage(self::TYPES[$import])->load($record->targetId);
    return $entity instanceof FieldableEntityInterface ? $entity : NULL;
  }

  /**
   * Returns the entity an item of an import became; it must exist.
   */
  protected function existing(string $import, string $key): FieldableEntityInterface {
    $entity = $this->entityOf($import, $key);
    $this->assertNotNull($entity, "$import $key does not exist");
    return $entity;
  }

  /**
   * Asserts an amount; the field keeps more decimals than were imported.
   */
  protected function assertAmount(string $expected, mixed $actual): void {
    $this->assertEqualsWithDelta((float) $expected, (float) $actual, 0.000001);
  }

  /**
   * The accounts and items arrive with renamed fields, texts and prices.
   */
  public function testAccountsAndItems(): void {
    $this->makeImports();

    $this->assertSame(RunStatus::Completed, $this->runImport('accounts', $this->siteA['customers']));
    $this->assertSame(RunStatus::Completed, $this->runImport('items', $this->siteA['products']));

    $acme = $this->existing('accounts', 'C-001');
    $this->assertSame('Acme BV', $acme->label());
    $this->assertSame('C-001', $acme->get('number')->value);
    $this->assertSame('1', $acme->get('source_id')->value);
    $this->assertSame('basic_html', $acme->get('notes')->format);
    $this->assertStringContainsString('<strong>first</strong>', $acme->get('notes')->value);
    $this->assertTrue($acme->get('status')->value == 1);
    // A customer that is switched off at the source is not published here.
    $this->assertEquals(0, $this->existing('accounts', 'C-003')->get('status')->value);
    $this->assertTrue($this->existing('accounts', 'C-002')->get('notes')->isEmpty(), 'No description: no notes.');

    $widget = $this->existing('items', '10');
    $this->assertAmount('9.95', $widget->get('list_price')->number);
    $this->assertSame('EUR', $widget->get('list_price')->currency_code);
    $this->assertSame('plain_text', $widget->get('summary')->format);
    $this->assertAmount('129.00', $this->existing('items', '11')->get('list_price')->number);
    $this->assertSame('USD', $this->existing('items', '11')->get('list_price')->currency_code);
    // A product without a price is imported without one.
    $sprocket = $this->existing('items', '12');
    $this->assertTrue($sprocket->get('list_price')->isEmpty());
    $this->assertEquals(0, $sprocket->get('status')->value);
  }

  /**
   * An agreement refers to the account and item it belongs to, by their keys.
   */
  public function testAgreementsReferToAccountsAndItems(): void {
    $this->makeImports();
    $this->runImport('accounts', $this->siteA['customers']);
    $this->runImport('items', $this->siteA['products']);

    $this->assertSame(RunStatus::Completed, $this->runImport('agreements', $this->siteA['productPrices']));

    $agreement = $this->existing('agreements', '100');
    $this->assertSame('Widget for Acme BV', $agreement->label());
    $this->assertSame($this->existing('items', '10')->id(), $agreement->get('item')->target_id);
    $this->assertSame($this->existing('accounts', 'C-001')->id(), $agreement->get('account')->target_id);
    $this->assertSame('Acme BV', $agreement->get('account')->entity?->label(), 'The relation can be followed.');
    $this->assertAmount('8.50', $agreement->get('price')->number);
    $this->assertAmount('99.00', $this->existing('agreements', '101')->get('price')->number);
    $other = $this->existing('agreements', '102');
    $this->assertSame('Widget for Globex', $other->label());
    $this->assertSame($this->existing('accounts', 'C-002')->id(), $other->get('account')->target_id);
    $this->assertSame(3, $this->total('agreement'));
  }

  /**
   * Agreements imported before their accounts wait, and are handled afterwards.
   */
  public function testAgreementsWaitForTheirAccounts(): void {
    $this->makeImports();
    $this->runImport('items', $this->siteA['products']);

    // The accounts are not there yet: the agreement run cannot be finished.
    $result = $this->drive('agreements', $this->siteA['productPrices']);
    $this->assertSame(DriveStatus::Waiting, $result->status);
    $this->assertSame(0, $this->total('agreement'));

    // Once the accounts are in, the waiting items are due again (a worker
    // would take them after the delay; here the delay is skipped).
    $this->runImport('accounts', $this->siteA['customers']);
    $this->container->get('database')->update('import_item')->fields(['next_attempt' => 0])->execute();
    $done = $this->container->get('import_engine.worker')->work(new RunBudget(), 'test', 'default', 50, TRUE);
    $this->assertSame(3, $done->items);
    $this->assertSame(1, $done->finished);
    $this->assertSame(3, $this->total('agreement'));
  }

  /**
   * Running the imports again changes nothing; a change at the source arrives.
   */
  public function testSecondRunChangesOnlyWhatChanged(): void {
    $this->makeImports();
    $this->runImport('accounts', $this->siteA['customers']);
    $changed = $this->existing('accounts', 'C-001')->get('changed')->value;

    $customers = $this->siteA['customers'];
    $customers[1]['label'] = 'Globex International';
    $this->assertSame(RunStatus::Completed, $this->runImport('accounts', $customers));

    $this->assertSame('Globex International', $this->existing('accounts', 'C-002')->label());
    $this->assertSame($changed, $this->existing('accounts', 'C-001')->get('changed')->value, 'An item that did not change is not saved again.');
  }

  /**
   * An account that leaves the source is unpublished; its agreements stay.
   */
  public function testAccountThatLeavesTheSourceIsUnpublished(): void {
    $this->makeImports(['accounts' => ['delete_threshold_percent' => 40]]);
    $this->runImport('accounts', $this->siteA['customers']);
    $this->runImport('items', $this->siteA['products']);
    $this->runImport('agreements', $this->siteA['productPrices']);

    $this->assertSame(RunStatus::Completed, $this->runImport('accounts', array_slice($this->siteA['customers'], 1)));

    $gone = $this->existing('accounts', 'C-001');
    $this->assertEquals(0, $gone->get('status')->value);
    $this->assertSame(3, $this->total('agreement'), 'Unpublishing an account does not touch its agreements.');
  }

  /**
   * A deleted account takes its agreements with it, and they come back with it.
   */
  public function testDeletedAccountTakesItsAgreementsAndComesBack(): void {
    $this->makeImports(['accounts' => ['delete_policy' => 'delete', 'delete_threshold_percent' => 40]]);
    $this->runImport('accounts', $this->siteA['customers']);
    $this->runImport('items', $this->siteA['products']);
    $this->runImport('agreements', $this->siteA['productPrices']);
    $this->assertSame(3, $this->total('agreement'));
    $first_id = $this->existing('accounts', 'C-001')->id();

    // C-001 leaves the source, and so do its prices.
    $this->assertSame(RunStatus::Completed, $this->runImport('accounts', array_slice($this->siteA['customers'], 1)));
    $this->assertNull(Account::load($first_id), 'The account is deleted.');
    $this->assertNull($this->container->get('import_engine.mapping_store')->find('accounts', '["C-001"]'), 'And forgotten.');
    $this->assertSame(1, $this->total('agreement'), 'Its two agreements went with it.');

    // The agreements import runs on the data that is left: what left is
    // swept, though its entities are already gone, which is no problem.
    $remaining = array_values(array_filter($this->siteA['productPrices'], static fn (array $price): bool => $price['customer']['customerNumber'] !== 'C-001'));
    $definition = ImportDefinition::load('agreements');
    $definition?->set('delete_threshold_percent', 80)->save();
    $this->assertSame(RunStatus::Completed, $this->runImport('agreements', $remaining));
    $this->assertSame(1, $this->total('agreement'));

    // C-001 returns, and so do its prices: the account is a new entity and the
    // agreements refer to it, though their sources did not change.
    $this->assertSame(RunStatus::Completed, $this->runImport('accounts', $this->siteA['customers']));
    $back = $this->existing('accounts', 'C-001');
    $this->assertNotSame($first_id, $back->id(), 'It is a new entity.');
    $this->assertSame(RunStatus::Completed, $this->runImport('agreements', $this->siteA['productPrices']));

    $this->assertSame(3, $this->total('agreement'));
    $this->assertSame($back->id(), $this->existing('agreements', '100')->get('account')->target_id);
    $this->assertSame($back->id(), $this->existing('agreements', '101')->get('account')->target_id);
  }

  /**
   * A second price for the same account and item is refused and kept dead.
   */
  public function testDuplicateAgreementGoesToTheDeadLetterQueue(): void {
    $this->makeImports();
    $this->runImport('accounts', $this->siteA['customers']);
    $this->runImport('items', $this->siteA['products']);
    $prices = $this->siteA['productPrices'];
    // The source has a second price for the same account and product.
    $prices[] = [
      'id' => '103',
      'uuid' => 'r103',
      'product' => ['id' => '10', 'label' => 'Widget'],
      'customer' => ['id' => '1', 'customerNumber' => 'C-001', 'label' => 'Acme BV'],
      'price' => ['number' => '7.00', 'currencyCode' => 'EUR'],
    ];

    $this->assertSame(RunStatus::CompletedWithErrors, $this->runImport('agreements', $prices));

    $this->assertSame(3, $this->total('agreement'));
    $run = $this->container->get('import_engine.run_manager')->latest('agreements');
    $this->assertNotNull($run);
    $dead = $this->container->get('import_engine.item_storage')->listItems([(int) $run->id()], ItemState::Dead, 10);
    $this->assertCount(1, $dead);
    $this->assertSame('["103"]', $dead[0]->key);
    $this->assertStringContainsString('This account already has an agreement for this item.', (string) $dead[0]->error);
  }

  /**
   * A price for an account that never comes ends in the dead letter queue.
   */
  public function testAgreementForUnknownAccountEndsDead(): void {
    $this->makeImports([
      'agreements' => [
        'resilience' => [
          'max_attempts' => 1,
          'backoff' => 'fixed',
          'retry_delay' => 60,
          'dlq_enabled' => TRUE,
          'max_repeated_pages' => 3,
        ],
      ],
    ]);
    $this->runImport('accounts', array_slice($this->siteA['customers'], 0, 1));
    $this->runImport('items', $this->siteA['products']);
    $prices = [$this->siteA['productPrices'][0], $this->siteA['productPrices'][2]];

    $this->assertSame(RunStatus::CompletedWithErrors, $this->runImport('agreements', $prices));

    $this->assertSame(1, $this->total('agreement'));
    $run = $this->container->get('import_engine.run_manager')->latest('agreements');
    $this->assertNotNull($run);
    $dead = $this->container->get('import_engine.item_storage')->listItems([(int) $run->id()], ItemState::Dead, 10);
    $this->assertStringContainsString('"C-002" of the import "accounts" is not imported yet', (string) $dead[0]->error);
  }

}
