<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\BackoffStrategy;
use Drupal\import_engine\DeletePolicy;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the import definition config entity and its schema.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ImportDefinitionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'import_engine'];

  /**
   * Returns the values of a valid definition.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected static function validValues(): array {
    return [
      'id' => 'customers',
      'label' => 'Customers',
      'description' => 'Imports the customers of site A.',
      'source' => [
        'plugin' => 'http',
        'configuration' => [
          'url' => 'https://site-a.ddev.site/graphql/catalog',
          'method' => 'POST',
          'headers' => [],
          'query' => [],
          'body' => '{"query": "{ customers { items { id } } }"}',
          'items_path' => 'data.customers.items',
          'timeout' => 30,
          'format' => 'auto',
          'csv_delimiter' => ',',
        ],
      ],
      'source_key' => ['customer_code', 'site.code'],
      'pagination' => [
        'plugin' => 'offset_limit',
        'configuration' => [
          'target' => 'body',
          'offset_param' => 'variables.offset',
          'limit_param' => 'variables.limit',
          'page_size' => 50,
          'total_path' => 'data.customers.totalCount',
          'stop_on_short_page' => FALSE,
        ],
      ],
      'authentication' => [
        'plugin' => 'api_key_header',
        'configuration' => ['header' => 'api-key', 'env_var' => 'SITE_A_API_KEY'],
      ],
      'target' => [
        'plugin' => 'entity',
        'configuration' => ['entity_type' => 'node', 'bundle' => 'account', 'owner' => 0],
      ],
      'mapping' => [
        [
          'target_field' => 'title',
          'mapper' => ['plugin' => 'string', 'sources' => ['value' => 'label'], 'settings' => []],
        ],
        [
          'target_field' => 'field_rate',
          'mapper' => [
            'plugin' => 'money',
            'sources' => ['amount' => 'price.number', 'currency' => 'price.currency_code'],
            'settings' => [],
          ],
        ],
      ],
    ];
  }

  /**
   * A definition is saved, loaded and read back through typed getters.
   */
  public function testSaveAndLoad(): void {
    ImportDefinition::create(self::validValues())->save();

    $definition = ImportDefinition::load('customers');
    $this->assertInstanceOf(ImportDefinition::class, $definition);
    $this->assertSame('Customers', $definition->label());
    $this->assertSame('http', $definition->getSource()['plugin']);
    $this->assertSame(['customer_code', 'site.code'], $definition->getSourceKey());
    $this->assertSame('offset_limit', $definition->getPagination()['plugin']);
    $this->assertSame('api_key_header', $definition->getAuthentication()['plugin']);
    $this->assertSame('entity', $definition->getTarget()['plugin']);
    $this->assertSame('node', $definition->getTarget()['configuration']['entity_type']);
    $this->assertSame('account', $definition->getTarget()['configuration']['bundle']);
    $this->assertCount(2, $definition->getMapping());
    $this->assertSame(
      ['amount' => 'price.number', 'currency' => 'price.currency_code'],
      $definition->getMapping()[1]['mapper']['sources'],
    );
  }

  /**
   * Options that are not given get safe defaults.
   */
  public function testDefaults(): void {
    $definition = ImportDefinition::create(self::validValues());

    $this->assertSame(DeletePolicy::Unpublish, $definition->getDeletePolicy());
    $this->assertSame(5, $definition->getMaxAttempts());
    $this->assertSame(BackoffStrategy::Exponential, $definition->getBackoff());
    $this->assertTrue($definition->isDlqEnabled());
    $this->assertSame(3, $definition->getMaxRepeatedPages());
    $this->assertSame(60, $definition->getRetryDelay());
    $this->assertSame(20, $definition->getDeleteThresholdPercent());
    $this->assertSame([], $definition->getReporters());
    $this->assertSame(['enabled' => TRUE, 'threshold' => 5, 'cooldown' => 60], $definition->getBreaker());
    $this->assertSame('default', $definition->getPool());
    $this->assertTrue($definition->status());
  }

  /**
   * A complete valid definition has no violations.
   */
  public function testValidDefinitionPassesValidation(): void {
    $definition = ImportDefinition::create(self::validValues() + [
      'delete_policy' => 'delete',
      'resilience' => [
        'max_attempts' => 3,
        'backoff' => 'linear',
        'retry_delay' => 30,
        'dlq_enabled' => FALSE,
        'max_repeated_pages' => 5,
      ],
      'pool' => 'heavy',
    ]);

    $this->assertCount(0, $definition->getTypedData()->validate());
    $definition->save();
    $loaded = ImportDefinition::load('customers');
    $this->assertSame(DeletePolicy::Delete, $loaded?->getDeletePolicy());
    $this->assertSame(BackoffStrategy::Linear, $loaded->getBackoff());
    $this->assertFalse($loaded->isDlqEnabled());
    $this->assertSame(5, $loaded->getMaxRepeatedPages());
    $this->assertSame(30, $loaded->getRetryDelay());
    $this->assertSame('heavy', $loaded->getPool());
  }

  /**
   * Invalid values are reported on the property that holds them.
   *
   * @param array<string, mixed> $changes
   *   Values that replace those of the valid definition.
   * @param string $property_path
   *   The property that must report a violation.
   */
  #[DataProvider('invalidValuesProvider')]
  public function testInvalidValuesAreReported(array $changes, string $property_path): void {
    $definition = ImportDefinition::create($changes + self::validValues());

    $paths = [];
    foreach ($definition->getTypedData()->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    $this->assertContains($property_path, $paths, 'Violations were reported on: ' . implode(', ', $paths));
  }

  /**
   * Data provider.
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   The changed values and the property that must report them.
   */
  public static function invalidValuesProvider(): array {
    $resilience = static fn (int $attempts, string $backoff, int $repeats = 3): array => [
      'resilience' => [
        'max_attempts' => $attempts,
        'backoff' => $backoff,
        'retry_delay' => 60,
        'dlq_enabled' => TRUE,
        'max_repeated_pages' => $repeats,
      ],
    ];
    $row = static fn (string $field, string $path): array => [
      'mapping' => [
        [
          'target_field' => $field,
          'mapper' => ['plugin' => 'string', 'sources' => ['value' => $path], 'settings' => []],
        ],
      ],
    ];
    $source_path = 'mapping.0.mapper.sources.value';

    return [
      'no key paths' => [['source_key' => []], 'source_key'],
      'too many key paths' => [['source_key' => ['a', 'b', 'c', 'd', 'e', 'f']], 'source_key'],
      'key path with an empty segment' => [['source_key' => ['a..b']], 'source_key.0'],
      'unknown delete policy' => [['delete_policy' => 'archive'], 'delete_policy'],
      'no breaker threshold' => [
        ['breaker' => ['enabled' => TRUE, 'threshold' => 0, 'cooldown' => 60]],
        'breaker.threshold',
      ],
      'no breaker cooldown' => [
        ['breaker' => ['enabled' => TRUE, 'threshold' => 5, 'cooldown' => 0]],
        'breaker.cooldown',
      ],
      'threshold above 100' => [['delete_threshold_percent' => 101], 'delete_threshold_percent'],
      'negative threshold' => [['delete_threshold_percent' => -1], 'delete_threshold_percent'],
      'unknown reporter plugin' => [
        ['reporters' => [['plugin' => 'pigeon', 'configuration' => []]]],
        'reporters.0.plugin',
      ],
      'mail reporter with a bad address' => [
        [
          'reporters' => [
            ['plugin' => 'mail', 'configuration' => ['only_on_problems' => FALSE, 'recipients' => ['nobody']]],
          ],
        ],
        'reporters.0.configuration.recipients.0',
      ],
      'mail reporter without recipients' => [
        ['reporters' => [['plugin' => 'mail', 'configuration' => ['only_on_problems' => FALSE, 'recipients' => []]]]],
        'reporters.0.configuration.recipients',
      ],
      'no attempts' => [$resilience(0, 'fixed'), 'resilience.max_attempts'],
      'too many attempts' => [$resilience(99, 'fixed'), 'resilience.max_attempts'],
      'no repeated pages allowed' => [$resilience(3, 'fixed', 0), 'resilience.max_repeated_pages'],
      'too many repeated pages' => [$resilience(3, 'fixed', 99), 'resilience.max_repeated_pages'],
      'unknown backoff' => [$resilience(3, 'random'), 'resilience.backoff'],
      'pool is not a machine name' => [['pool' => 'Heavy Pool'], 'pool'],
      'missing target entity type' => [
        [
          'target' => [
            'plugin' => 'entity',
            'configuration' => ['entity_type' => '', 'bundle' => 'account', 'owner' => 0],
          ],
        ],
        'target.configuration.entity_type',
      ],
      'target bundle is not a machine name' => [
        [
          'target' => [
            'plugin' => 'entity',
            'configuration' => ['entity_type' => 'node', 'bundle' => 'My Bundle', 'owner' => 0],
          ],
        ],
        'target.configuration.bundle',
      ],
      'unknown target plugin' => [
        ['target' => ['plugin' => 'carrier_pigeon', 'configuration' => []]],
        'target.plugin',
      ],
      'unknown source plugin' => [
        ['source' => ['plugin' => 'carrier_pigeon', 'configuration' => []]],
        'source.plugin',
      ],
      'source path with an empty segment' => [$row('title', 'price..number'), $source_path],
      'source path with a trailing dot' => [$row('title', 'price.'), $source_path],
      'empty source path' => [$row('title', ''), $source_path],
      'target field is not a machine name' => [$row('Title', 'label'), 'mapping.0.target_field'],
    ];
  }

}
