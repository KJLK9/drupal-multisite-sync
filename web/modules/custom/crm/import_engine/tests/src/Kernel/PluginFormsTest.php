<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\import_engine\Mapper\MapperInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the settings forms the plugins describe themselves.
 *
 * Every plugin must be able to show its configuration as a form and take the
 * form back as the same configuration, in the types the schema wants.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class PluginFormsTest extends NodeTestBase {

  /**
   * Creates a plugin.
   *
   * @param string $manager
   *   The short name of the plugin manager, for example source.
   * @param string $id
   *   The plugin ID.
   * @param array<string, mixed> $configuration
   *   The configuration.
   *
   * @return \Drupal\Core\Plugin\PluginFormInterface&\Drupal\Component\Plugin\ConfigurableInterface
   *   The plugin.
   */
  protected function plugin(string $manager, string $id, array $configuration = []): PluginFormInterface&ConfigurableInterface {
    $plugins = $this->container->get('plugin.manager.import_engine_' . $manager);
    $this->assertInstanceOf(PluginManagerInterface::class, $plugins);
    $plugin = $plugins->createInstance($id, $configuration);
    $this->assertInstanceOf(PluginFormInterface::class, $plugin);
    $this->assertInstanceOf(ConfigurableInterface::class, $plugin);
    return $plugin;
  }

  /**
   * Collects what a person would see filled in: the default of every field.
   *
   * @param array<string, mixed> $form
   *   The form.
   *
   * @return array<string, mixed>
   *   The values by field name.
   */
  protected function defaults(array $form): array {
    $values = [];
    foreach ($form as $key => $element) {
      if (str_starts_with((string) $key, '#') || !is_array($element)) {
        continue;
      }
      if (array_key_exists('#default_value', $element)) {
        $default = $element['#default_value'];
        $values[$key] = $default instanceof EntityInterface ? $default->id() : $default;
      }
    }
    return $values;
  }

  /**
   * Returns the configuration of every plugin, as a form would be filled in.
   *
   * @return array<string, array{string, string, array<string, mixed>}>
   *   The manager, the plugin ID and a configuration that uses every setting.
   */
  public static function pluginProvider(): array {
    $headers = ['X-First' => 'one', 'X-Time' => '12:30'];
    return [
      'http source' => ['source', 'http', [
        'url' => 'https://site-a.test/api',
        'method' => 'POST',
        'headers' => $headers,
        'query' => ['a' => '1', 'b' => 'x=y'],
        'body' => '{"q": 1}',
        'items_path' => 'data.items',
        'timeout' => 20,
        'format' => 'json',
        'csv_delimiter' => ';',
      ],
      ],
      'graphql source' => ['source', 'graphql', [
        'url' => 'https://site-a.test/graphql',
        'query' => '{ customers { items { id } } }',
        'variables' => '{"a": 1}',
        'headers' => $headers,
        'items_path' => 'data.customers.items',
        'timeout' => 15,
      ],
      ],
      'no pagination' => ['pagination', 'none', []],
      'offset and limit' => ['pagination', 'offset_limit', [
        'target' => 'body',
        'offset_param' => 'variables.offset',
        'limit_param' => 'variables.limit',
        'page_size' => 25,
        'total_path' => 'data.total',
        'stop_on_short_page' => TRUE,
      ],
      ],
      'page' => ['pagination', 'page', [
        'target' => 'query',
        'page_param' => 'p',
        'size_param' => 'per_page',
        'page_size' => 10,
        'first_page' => 0,
        'total_path' => 'meta.total',
        'last_page_path' => 'meta.last',
      ],
      ],
      'next url' => ['pagination', 'next_url', ['next_path' => 'next', 'total_path' => 'count']],
      'no authentication' => ['authentication', 'none', []],
      'api key in a header' => ['authentication', 'api_key_header', ['header' => 'x-api-key', 'env_var' => 'MY_KEY']],
      'entity target' => ['target', 'entity', ['entity_type' => 'node', 'bundle' => 'account', 'owner' => 1]],
      'string mapper' => ['mapper', 'string', ['trim' => FALSE, 'empty_as_null' => FALSE]],
      'text mapper' => ['mapper', 'text', ['format' => 'plain_text']],
      'number mapper' => ['mapper', 'number', []],
      'join mapper' => ['mapper', 'join', ['separator' => ' / ', 'skip_empty' => FALSE]],
      'boolean mapper' => [
        'mapper',
        'boolean',
        ['true_values' => ['ja', 'J'], 'false_values' => ['nee'], 'when_empty' => 'fail'],
      ],
      'money mapper' => ['mapper', 'money', ['default_currency' => 'USD']],
      'reference mapper' => ['mapper', 'reference', ['definition' => 'products', 'required' => FALSE]],
      'timestamp mapper' => ['mapper', 'timestamp', ['format' => 'Y-m-d']],
      'log reporter' => ['reporter', 'log', ['only_on_problems' => TRUE]],
      'mail reporter' => [
        'reporter',
        'mail',
        ['only_on_problems' => TRUE, 'recipients' => ['a@example.com', 'b@example.com']],
      ],
    ];
  }

  /**
   * A form built from a configuration, submitted unchanged, gives it back.
   *
   * The result must also be valid for the config schema of that plugin.
   *
   * @param string $manager
   *   The short name of the plugin manager.
   * @param string $id
   *   The plugin ID.
   * @param array<string, mixed> $configuration
   *   The configuration.
   */
  #[DataProvider('pluginProvider')]
  public function testFormRoundTrip(string $manager, string $id, array $configuration): void {
    $plugin = $this->plugin($manager, $id, $configuration);
    $expected = $plugin->getConfiguration();
    $form_state = new FormState();

    $form = $plugin->buildConfigurationForm([], $form_state);
    $form_state->setValues($this->defaults($form));
    $plugin->validateConfigurationForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors(), 'The form is valid as it comes.');
    $plugin->submitConfigurationForm($form, $form_state);

    $actual = $plugin->getConfiguration();
    // The order of the settings does not matter, their values and types do.
    ksort($expected);
    ksort($actual);
    $this->assertSame($expected, $actual);
    $typed = $this->container->get('config.typed')->createFromNameAndData('import_engine.' . $manager . '.' . $id, $plugin->getConfiguration());
    $paths = [];
    foreach ($typed->validate() as $violation) {
      $paths[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    $this->assertSame([], $paths);
  }

  /**
   * Every plugin of every kind has a test configuration above.
   */
  public function testEveryPluginIsCovered(): void {
    $covered = [];
    foreach (self::pluginProvider() as [$manager, $id]) {
      $covered[] = $manager . ':' . $id;
    }
    $found = [];
    foreach (['source', 'pagination', 'authentication', 'target', 'mapper', 'reporter'] as $manager) {
      foreach (array_keys($this->container->get('plugin.manager.import_engine_' . $manager)->getDefinitions()) as $id) {
        $found[] = $manager . ':' . $id;
      }
    }

    $this->assertEqualsCanonicalizing($found, $covered);
  }

  /**
   * Maps and lists written as text are read back as maps and lists.
   */
  public function testTextBecomesListsAndMaps(): void {
    $source = $this->plugin('source', 'http');
    $form = $source->buildConfigurationForm([], new FormState());
    $form_state = (new FormState())->setValues(array_replace($this->defaults($form), [
      'url' => 'https://site-a.test/api',
      'headers' => "Accept: application/json\nX-Time:12:30",
      'query' => "a=1\nb=2=3",
      'timeout' => '45',
    ]));
    $source->submitConfigurationForm($form, $form_state);

    $configuration = $source->getConfiguration();
    $this->assertSame(['Accept' => 'application/json', 'X-Time' => '12:30'], $configuration['headers']);
    $this->assertSame(['a' => '1', 'b' => '2=3'], $configuration['query']);
    $this->assertSame(45, $configuration['timeout'], 'A number from a form is a number.');
  }

  /**
   * A line that is not a pair is an error on its field.
   */
  public function testBadPairsAreFormErrors(): void {
    $source = $this->plugin('source', 'http');
    $form_state = (new FormState())->setValues(['headers' => 'nonsense', 'query' => "ok=1\n=x"]);
    $form = [];

    $source->validateConfigurationForm($form, $form_state);

    $this->assertSame(['headers', 'query'], array_keys($form_state->getErrors()));
  }

  /**
   * Lists of texts, a currency and mail recipients are tidied up.
   */
  public function testNormalizing(): void {
    $form = [];
    $money = $this->plugin('mapper', 'money');
    $money->submitConfigurationForm($form, (new FormState())->setValues(['default_currency' => ' usd ']));
    $this->assertSame('USD', $money->getConfiguration()['default_currency']);

    $boolean = $this->plugin('mapper', 'boolean');
    $boolean->submitConfigurationForm($form, (new FormState())->setValues([
      'true_values' => " Ja\n\n J ",
      'false_values' => "Nee\r\nN",
      'when_empty' => 'true',
    ]));
    $this->assertSame(['Ja', 'J'], $boolean->getConfiguration()['true_values']);
    $this->assertSame(['Nee', 'N'], $boolean->getConfiguration()['false_values']);
  }

  /**
   * Mail recipients must be email addresses.
   */
  public function testMailRecipientsAreValidated(): void {
    $reporter = $this->plugin('reporter', 'mail');
    $form_state = (new FormState())->setValues(['recipients' => "ok@example.com\nnobody"]);
    $form = [];

    $reporter->validateConfigurationForm($form, $form_state);

    $this->assertSame(['recipients'], array_keys($form_state->getErrors()));
    $this->assertStringContainsString('"nobody" is not an email address', (string) $form_state->getErrors()['recipients']);
  }

  /**
   * The entity target offers every bundle and splits the choice in two.
   */
  public function testEntityTargetChoosesTypeAndBundle(): void {
    $target = $this->plugin('target', 'entity');
    $form = $target->buildConfigurationForm([], new FormState());

    $options = $form['content']['#options'];
    $this->assertSame('Account', $options['Content']['node:account']);
    $this->assertArrayNotHasKey('Import run', $options);

    $target->submitConfigurationForm($form, (new FormState())->setValues(['content' => 'node:item', 'owner' => '1']));
    $this->assertSame(['entity_type' => 'node', 'bundle' => 'item', 'owner' => 1], $target->getConfiguration());

    $form_state = (new FormState())->setValues(['content' => '']);
    $target->validateConfigurationForm($form, $form_state);
    $this->assertSame(['content'], array_keys($form_state->getErrors()));
  }

  /**
   * A plugin without settings has an empty form, so it needs nothing special.
   */
  public function testPluginWithoutSettingsHasEmptyForm(): void {
    $this->assertSame([], $this->plugin('pagination', 'none')->buildConfigurationForm([], new FormState()));
    $this->assertInstanceOf(MapperInterface::class, $this->plugin('mapper', 'number'));
    $this->assertSame([], $this->plugin('mapper', 'number')->buildConfigurationForm([], new FormState()));
  }

}
