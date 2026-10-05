<?php

declare(strict_types=1);

namespace Drupal\Tests\site_b_catalog\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\import_engine\Drive\DriveStatus;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\FakeSource;
use Drupal\Tests\import_engine\Kernel\StorageTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the imports that fill the content model, with data shaped like site A.
 *
 * The three imports are the ones to make in the wizard; the README of the
 * module shows them as tables. Here they are made in code, so the model and
 * the mapping are proven to work together: accounts and items first, then the
 * agreements that refer to them.
 */
#[Group('site_b_catalog')]
#[RunTestsInSeparateProcesses]
class CatalogImportTest extends StorageTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'money_field',
    'import_engine',
    'site_b_catalog',
  ];

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter', 'site_b_catalog']);
    User::create(['name' => 'importer', 'status' => 1])->save();
    // Site B has this format from its standard profile.
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => ['filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<p> <strong> <em>']]],
    ])->save();
    $this->siteA = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/site_a_catalog.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Saves one of the imports.
   *
   * @param string $id
   *   The ID of the import.
   * @param string $bundle
   *   The content type it writes.
   * @param string $key
   *   The path of the key of an item.
   * @param list<array<string, mixed>> $mapping
   *   The mapping rows.
   */
  protected function saveImport(string $id, string $bundle, string $key, array $mapping): ImportDefinition {
    $definition = ImportDefinition::create([
      'id' => $id,
      'label' => ucfirst($id),
      'source' => [
        'plugin' => 'graphql',
        'configuration' => [
          'url' => 'https://site-a.test/graphql/catalog',
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
      'target' => [
        'plugin' => 'entity',
        'configuration' => ['entity_type' => 'node', 'bundle' => $bundle, 'owner' => 1],
      ],
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
   */
  protected function makeImports(): void {
    $this->saveImport('accounts', 'account', 'customerNumber', [
      self::row('title', 'string', ['value' => 'label']),
      self::row('field_account_number', 'string', ['value' => 'customerNumber']),
      self::row('field_notes', 'text', ['value' => 'description'], ['format' => 'basic_html']),
      self::row('field_source_id', 'string', ['value' => 'id']),
      self::row('status', 'boolean', ['value' => 'status']),
    ]);
    $this->saveImport('items', 'item', 'id', [
      self::row('title', 'string', ['value' => 'label']),
      self::row('field_list_price', 'money', ['amount' => 'basePrice.number', 'currency' => 'basePrice.currencyCode']),
      self::row('field_summary', 'text', ['value' => 'description'], ['format' => 'plain_text']),
      self::row('field_source_id', 'string', ['value' => 'id']),
      self::row('status', 'boolean', ['value' => 'status']),
    ]);
    $this->saveImport('agreements', 'agreement', 'id', [
      self::row('title', 'join', [
        'first' => 'product.label',
        'second' => 'customer.label',
      ], ['separator' => ' for ', 'skip_empty' => TRUE]),
      self::row('field_item', 'reference', ['id' => 'product.id'], ['definition' => 'items', 'required' => TRUE]),
      self::row('field_account', 'reference', [
        'id' => 'customer.customerNumber',
      ], ['definition' => 'accounts', 'required' => TRUE]),
      self::row('field_agreed_price', 'money', ['amount' => 'price.number', 'currency' => 'price.currencyCode']),
      self::row('field_source_id', 'string', ['value' => 'id']),
    ]);
  }

  /**
   * Runs an import completely, reading the given items.
   *
   * @param string $id
   *   The ID of the import.
   * @param list<array<string, mixed>> $items
   *   The items of the source, in one page.
   */
  protected function runImport(string $id, array $items): RunStatus {
    $definition = ImportDefinition::load($id);
    $this->assertNotNull($definition);
    $run = $this->container->get('import_engine.run_starter')->start($definition, Trigger::Drush);
    $result = $this->container->get('import_engine.run_driver')->drive($run, $definition, new RunBudget(), 'test', 50, new FakeSource([$items]));
    $this->assertSame(DriveStatus::Finished, $result->status, (string) $result->message);
    return $result->runStatus;
  }

  /**
   * Returns the node an item of an import became.
   */
  protected function nodeOf(string $import, string $key): Node {
    $record = $this->container->get('import_engine.mapping_store')->find($import, '["' . $key . '"]');
    $this->assertNotNull($record, "$import $key");
    $node = Node::load((int) $record->targetId);
    $this->assertInstanceOf(Node::class, $node);
    return $node;
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

    $acme = $this->nodeOf('accounts', 'C-001');
    $this->assertSame('account', $acme->bundle());
    $this->assertSame('Acme BV', $acme->label());
    $this->assertSame('C-001', $acme->get('field_account_number')->value);
    $this->assertSame('1', $acme->get('field_source_id')->value);
    $this->assertSame('basic_html', $acme->get('field_notes')->format);
    $this->assertStringContainsString('<strong>first</strong>', $acme->get('field_notes')->value);
    $this->assertTrue($acme->isPublished());
    // A customer that is switched off at the source is not published here.
    $this->assertFalse($this->nodeOf('accounts', 'C-003')->isPublished());
    $this->assertTrue($this->nodeOf('accounts', 'C-002')->get('field_notes')->isEmpty(), 'No description: no notes.');

    $widget = $this->nodeOf('items', '10');
    $this->assertAmount('9.95', $widget->get('field_list_price')->number);
    $this->assertSame('EUR', $widget->get('field_list_price')->currency_code);
    $this->assertSame('plain_text', $widget->get('field_summary')->format);
    $gadget = $this->nodeOf('items', '11');
    $this->assertAmount('129.00', $gadget->get('field_list_price')->number);
    $this->assertSame('USD', $gadget->get('field_list_price')->currency_code);
    // A product without a price is imported without one.
    $sprocket = $this->nodeOf('items', '12');
    $this->assertTrue($sprocket->get('field_list_price')->isEmpty());
    $this->assertFalse($sprocket->isPublished());
  }

  /**
   * An agreement refers to the account and item it belongs to, by their keys.
   */
  public function testAgreementsReferToAccountsAndItems(): void {
    $this->makeImports();
    $this->runImport('accounts', $this->siteA['customers']);
    $this->runImport('items', $this->siteA['products']);

    $this->assertSame(RunStatus::Completed, $this->runImport('agreements', $this->siteA['productPrices']));

    $agreement = $this->nodeOf('agreements', '100');
    $this->assertSame('agreement', $agreement->bundle());
    $this->assertSame('Widget for Acme BV', $agreement->label());
    $this->assertSame((string) $this->nodeOf('items', '10')->id(), (string) $agreement->get('field_item')->target_id);
    $this->assertSame((string) $this->nodeOf('accounts', 'C-001')->id(), (string) $agreement->get('field_account')->target_id);
    $this->assertAmount('8.50', $agreement->get('field_agreed_price')->number);
    $this->assertAmount('99.00', $this->nodeOf('agreements', '101')->get('field_agreed_price')->number);
    // The same item for another account is another agreement.
    $other = $this->nodeOf('agreements', '102');
    $this->assertSame('Widget for Globex', $other->label());
    $this->assertSame((string) $this->nodeOf('accounts', 'C-002')->id(), (string) $other->get('field_account')->target_id);
    $this->assertCount(3, array_filter(Node::loadMultiple(), static fn (Node $node): bool => $node->bundle() === 'agreement'));
  }

  /**
   * Agreements imported before their accounts wait, and are handled afterwards.
   */
  public function testAgreementsWaitForTheirAccounts(): void {
    $this->makeImports();

    $this->assertSame(RunStatus::Completed, $this->runImport('items', $this->siteA['products']));
    // The accounts are not there yet: the agreement run cannot be finished.
    $definition = ImportDefinition::load('agreements');
    $this->assertNotNull($definition);
    $run = $this->container->get('import_engine.run_starter')->start($definition, Trigger::Drush);
    $result = $this->container->get('import_engine.run_driver')->drive($run, $definition, new RunBudget(), 'test', 50, new FakeSource([$this->siteA['productPrices']]));
    $this->assertSame(DriveStatus::Waiting, $result->status);
    $this->assertCount(0, array_filter(Node::loadMultiple(), static fn (Node $node): bool => $node->bundle() === 'agreement'));

    // Once the accounts are in, the waiting items are due again (a worker
    // would take them after the delay; here the delay is skipped).
    $this->runImport('accounts', $this->siteA['customers']);
    $this->container->get('database')->update('import_item')->fields(['next_attempt' => 0])->execute();
    $done = $this->container->get('import_engine.worker')->work(new RunBudget(), 'test', 'default', 50, TRUE);
    $this->assertSame(3, $done->items);
    $this->assertSame(1, $done->finished);
    $this->assertCount(3, array_filter(Node::loadMultiple(), static fn (Node $node): bool => $node->bundle() === 'agreement'));
  }

  /**
   * Running the imports again changes nothing; a change at the source arrives.
   */
  public function testSecondRunChangesOnlyWhatChanged(): void {
    $this->makeImports();
    $this->runImport('accounts', $this->siteA['customers']);
    $changed = $this->nodeOf('accounts', 'C-001')->getChangedTime();

    $customers = $this->siteA['customers'];
    $customers[1]['label'] = 'Globex International';
    $this->assertSame(RunStatus::Completed, $this->runImport('accounts', $customers));

    $this->assertSame('Globex International', $this->nodeOf('accounts', 'C-002')->label());
    $this->assertSame($changed, $this->nodeOf('accounts', 'C-001')->getChangedTime(), 'An item that did not change is not saved again.');
  }

  /**
   * An account that leaves the source is unpublished, not deleted.
   */
  public function testAccountThatLeavesTheSourceIsUnpublished(): void {
    $this->makeImports();
    $this->runImport('accounts', $this->siteA['customers']);

    // One of three leaves: 33%, more than the default 20%, so allow it.
    $definition = ImportDefinition::load('accounts');
    $definition?->set('delete_threshold_percent', 40)->save();
    $this->assertSame(RunStatus::Completed, $this->runImport('accounts', array_slice($this->siteA['customers'], 0, 2)));

    $gone = $this->nodeOf('accounts', 'C-003');
    $this->assertFalse($gone->isPublished());
    $this->assertTrue($this->nodeOf('accounts', 'C-001')->isPublished());
  }

}
