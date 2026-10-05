<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine\Entity\ImportConnection;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine_ui\Controller\ConnectionController;
use Drupal\import_engine_ui\Form\ConnectionDeleteForm;
use Drupal\import_engine_ui\Form\ConnectionForm;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the screens that manage connections.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class ConnectionPagesTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'import_engine', 'import_engine_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser(['name' => 'alice'], ['administer import definitions']);
  }

  /**
   * Submits the connection form.
   *
   * @param array<string, mixed> $values
   *   The values of the form.
   * @param string|null $id
   *   The connection that is changed; none to add one.
   */
  protected function submit(array $values, ?string $id = NULL): FormStateInterface {
    $state = new FormState();
    $state->addBuildInfo('args', $id === NULL ? [] : [ImportConnection::load($id)]);
    $state->setValues($values + ['op' => 'Save']);
    $this->container->get('form_builder')->submitForm(ConnectionForm::class, $state);
    return $state;
  }

  /**
   * The values of a valid new GraphQL connection.
   *
   * @param array<string, mixed> $changes
   *   Values that replace the defaults, by section.
   *
   * @return array<string, mixed>
   *   The values.
   */
  protected function values(array $changes = []): array {
    return array_replace_recursive([
      'label' => 'Site A',
      'id' => 'site_a',
      'description' => 'The catalog.',
      'source' => [
        'plugin' => 'graphql',
        'settings' => ['url' => 'http://site-a.test/graphql', 'headers' => "X-Team: b\n", 'timeout' => 20],
      ],
      'authentication' => [
        'plugin' => 'api_key_header',
        'settings' => ['header' => 'api-key', 'env_var' => 'SITE_A_API_KEY'],
      ],
    ], $changes);
  }

  /**
   * Saves an import that uses the connection.
   */
  protected function importUsing(string $id, string $connection): void {
    ImportDefinition::create([
      'id' => $id,
      'label' => $id,
      'connection' => $connection,
      'source' => [
        'plugin' => 'graphql',
        'configuration' => [
          'query' => '{ x }',
          'variables' => '',
          'items_path' => 'data',
        ],
      ],
      'source_key' => ['id'],
      'target' => [
        'plugin' => 'entity',
        'configuration' => [
          'entity_type' => 'node',
          'bundle' => 'account',
          'owner' => 0,
        ],
      ],
    ])->save();
  }

  /**
   * Returns the messages shown, by type, and forgets them.
   *
   * @return array<string, list<string>>
   *   The messages.
   */
  protected function messages(): array {
    $messenger = $this->container->get('messenger');
    $messages = [];
    foreach ($messenger->all() as $type => $list) {
      $messages[$type] = array_values(array_map('strval', $list));
    }
    $messenger->deleteAll();
    return $messages;
  }

  /**
   * A connection is added with only the settings that belong to a connection.
   */
  public function testAddConnection(): void {
    $state = $this->submit($this->values());

    $this->assertSame([], $state->getErrors());
    $connection = ImportConnection::load('site_a');
    $this->assertSame('Site A', $connection?->label());
    $this->assertSame('The catalog.', $connection->getDescription());
    $this->assertSame([
      'plugin' => 'graphql',
      'configuration' => ['url' => 'http://site-a.test/graphql', 'headers' => ['X-Team' => 'b'], 'timeout' => 20],
    ], $connection->getSource());
    $this->assertSame('api_key_header', $connection->getAuthentication()['plugin']);
    $this->assertSame('SITE_A_API_KEY', $connection->getAuthentication()['configuration']['env_var']);
  }

  /**
   * The form shows the settings of a connection, not those of an import.
   */
  public function testFormShowsOnlyConnectionSettings(): void {
    $form = $this->container->get('form_builder')->getForm(ConnectionForm::class);

    $settings = array_filter(array_keys($form['source']['settings']), static fn ($key): bool => !str_starts_with((string) $key, '#'));
    $this->assertEqualsCanonicalizing(['url', 'headers', 'timeout'], $settings);
    $this->assertEqualsCanonicalizing(['graphql', 'http'], array_keys($form['source']['plugin']['#options']));
  }

  /**
   * A connection that does not hold together is not saved, and says why.
   */
  public function testInvalidConnectionIsRefused(): void {
    $state = $this->submit($this->values(['source' => ['settings' => ['url' => 'ftp://x.test']]]));

    $this->assertNotSame([], $state->getErrors());
    $this->assertNull(ImportConnection::load('site_a'));

    $state = $this->submit($this->values(['source' => ['settings' => ['headers' => 'no colon here']]]));
    $this->assertNotSame([], $state->getErrors());
    $this->assertNull(ImportConnection::load('site_a'));
  }

  /**
   * A connection is changed, and the ID stays.
   */
  public function testEditConnection(): void {
    $this->submit($this->values());

    $state = $this->submit($this->values([
      'label' => 'Site A (new)',
      'id' => 'other',
      'source' => ['settings' => ['timeout' => 45]],
    ]), 'site_a');

    $this->assertSame([], $state->getErrors());
    $this->assertNull(ImportConnection::load('other'));
    $connection = ImportConnection::load('site_a');
    $this->assertSame('Site A (new)', $connection?->label());
    $this->assertSame(45, $connection->getSource()['configuration']['timeout']);
  }

  /**
   * A new address is a reason to read everything again; the person is told.
   */
  public function testChangedAddressWarnsWhenImportsUseIt(): void {
    $this->submit($this->values());
    $this->importUsing('accounts', 'site_a');
    $this->messages();

    $this->submit($this->values(['source' => ['settings' => ['url' => 'http://site-b.test/graphql']]]), 'site_a');

    $messages = $this->messages();
    $this->assertStringContainsString('accounts', $messages['warning'][0] ?? '');
    $this->assertStringContainsString('Read every page again', $messages['warning'][0]);

    $this->submit($this->values(['source' => ['settings' => ['timeout' => 99, 'url' => 'http://site-b.test/graphql']]]), 'site_a');
    $this->assertArrayNotHasKey('warning', $this->messages());
  }

  /**
   * A connection that imports use stays the same kind of source.
   */
  public function testKindOfSourceCannotChangeUnderImports(): void {
    $this->submit($this->values());
    $this->importUsing('accounts', 'site_a');

    $state = $this->submit($this->values(['source' => ['plugin' => 'http', 'settings' => ['url' => 'http://x.test/a']]]), 'site_a');

    $this->assertNotSame([], $state->getErrors());
    $this->assertSame('graphql', ImportConnection::load('site_a')?->getSource()['plugin']);
  }

  /**
   * The list says where each connection goes and who uses it.
   */
  public function testList(): void {
    $this->submit($this->values());
    $this->submit($this->values(['label' => 'Spare', 'id' => 'spare']));
    $this->importUsing('accounts', 'site_a');
    $this->importUsing('items', 'site_a');

    $page = $this->container->get('class_resolver')->getInstanceFromDefinition(ConnectionController::class)->list();

    $rows = array_column($page['table']['#rows'], NULL, 1);
    $this->assertSame('accounts, items', $rows['site_a'][5]);
    $this->assertSame('http://site-a.test/graphql', $rows['site_a'][3]);
    $this->assertSame('api_key_header', $rows['site_a'][4]);
    $this->assertSame(['edit'], array_keys($rows['site_a'][6]['data']['#links']), 'No delete for a connection in use.');
    $this->assertSame(['edit', 'delete'], array_keys($rows['spare'][6]['data']['#links']));
  }

  /**
   * A connection that nothing uses is deleted after confirmation.
   */
  public function testDeleteAnUnusedConnection(): void {
    $this->submit($this->values());
    $state = new FormState();
    $state->addBuildInfo('args', [ImportConnection::load('site_a')]);
    $state->setValues(['op' => 'Confirm']);
    $this->messages();

    $this->container->get('form_builder')->submitForm(ConnectionDeleteForm::class, $state);

    $this->assertNull(ImportConnection::load('site_a'));
    $this->assertStringContainsString('is deleted', $this->messages()['status'][0] ?? '');
  }

  /**
   * A connection in use is not deleted; the form names the imports.
   */
  public function testDeleteIsRefusedWhenImportsUseIt(): void {
    $this->submit($this->values());
    $this->importUsing('accounts', 'site_a');
    $state = new FormState();
    $state->addBuildInfo('args', [ImportConnection::load('site_a')]);

    $form = $this->container->get('form_builder')->buildForm(ConnectionDeleteForm::class, $state);

    $this->assertStringContainsString('accounts', (string) $form['blocked']['#markup']);
    $this->assertArrayNotHasKey('submit', $form['actions']);

    $state = new FormState();
    $state->addBuildInfo('args', [ImportConnection::load('site_a')]);
    $state->setValues(['op' => 'Confirm']);
    $this->container->get('form_builder')->submitForm(ConnectionDeleteForm::class, $state);
    $this->assertNotNull(ImportConnection::load('site_a'));
    $this->assertNotNull(ImportDefinition::load('accounts'));
  }

}
