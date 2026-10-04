<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\import_engine\Run\Trigger;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine_ui\Form\RunStartForm;
use Drupal\import_engine_ui\Run\RunBatch;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\NodeTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests starting and continuing a run from the interface.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class RunStartTest extends NodeTestBase {

  use UserCreationTrait;

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
    'import_engine_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser(['name' => 'alice'], ['administer import runs', 'view import runs']);
  }

  /**
   * Saves an import that reads from a mocked source.
   *
   * @param list<\Psr\Http\Message\ResponseInterface> $responses
   *   The answers of the source, in order.
   * @param array<string, mixed> $values
   *   Values that replace those of the definition.
   */
  protected function httpImport(array $responses, array $values = []): ImportDefinition {
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
    $this->definition($this->accounts($values + [
      'authentication' => ['plugin' => 'none', 'configuration' => []],
      'pagination' => ['plugin' => 'none', 'configuration' => []],
      'source' => [
        'plugin' => 'http',
        'configuration' => [
          'url' => 'https://site-a.test/api',
          'method' => 'GET',
          'headers' => [],
          'query' => [],
          'body' => '',
          'items_path' => 'data',
          'timeout' => 10,
          'format' => 'auto',
          'csv_delimiter' => ',',
        ],
      ],
    ]))->save();
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);
    return $definition;
  }

  /**
   * Builds a response with accounts.
   *
   * @param list<int> $ids
   *   The account IDs.
   */
  protected function answer(array $ids): Response {
    $rows = array_map(static fn (int $id): array => ['id' => $id, 'name' => "Account $id", 'code' => "C$id"], $ids);
    return new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
  }

  /**
   * Submits the form of an import; the batch runs straight away.
   *
   * @param \Drupal\import_engine\Entity\ImportDefinition $definition
   *   The import.
   * @param array<string, mixed> $values
   *   The values of the form.
   */
  protected function submit(ImportDefinition $definition, array $values = []): FormStateInterface {
    $state = new FormState();
    $state->addBuildInfo('args', [$definition]);
    $state->setValues($values);
    $builder = $this->container->get('form_builder');
    $builder->submitForm(RunStartForm::class, $state);
    return $state;
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
   * A run is started, driven to the end and reported.
   */
  public function testStartsAndFinishesRun(): void {
    $definition = $this->httpImport([$this->answer([1, 2, 3])]);

    $this->submit($definition);

    $this->assertCount(3, Node::loadMultiple());
    $run = $this->container->get('import_engine.run_manager')->latest('customers');
    $this->assertNotNull($run);
    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $this->assertSame('ui', $run->getTrigger()->value);
    $this->assertFalse($run->isFullRun());
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $run->get('uid')->target_id);
    $this->assertSame(['status' => ['Run 1 completed: 1 pages read, 3 items handled.']], $this->messages());
  }

  /**
   * A full run can be asked for.
   */
  public function testFullRun(): void {
    $definition = $this->httpImport([$this->answer([1])]);

    $this->submit($definition, ['full' => 1]);

    $this->assertTrue($this->container->get('import_engine.run_manager')->latest('customers')?->isFullRun());
  }

  /**
   * An import with errors says so.
   */
  public function testRunWithErrors(): void {
    $definition = $this->httpImport([new Response(200, ['Content-Type' => 'application/json'], '{"data":[{"id":1,"name":"","code":"A"}]}')]);

    $this->submit($definition);

    $this->assertStringContainsString('completed with errors', $this->messages()['warning'][0]);
  }

  /**
   * A run that is not over is continued, not started again.
   */
  public function testContinuesTheActiveRun(): void {
    $run = $this->extractRows($this->pagesOf(1, 3)[0]);
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);
    $state = new FormState();
    $state->addBuildInfo('args', [$definition]);
    $form = $this->container->get('form_builder')->buildForm(RunStartForm::class, $state);
    $this->assertArrayNotHasKey('full', $form, 'The run is going on: there is nothing to choose.');
    $this->assertSame('Continue', (string) $form['actions']['submit']['#value']);

    $this->submit($definition);

    $this->assertCount(3, Node::loadMultiple());
    $this->assertSame(RunStatus::Completed, $this->reload($run)->getStatus());
    $this->assertSame((int) $run->id(), (int) $this->container->get('import_engine.run_manager')->latest('customers')?->id(), 'No second run was started.');
  }

  /**
   * An import that is switched off is not run.
   */
  public function testDisabledImportIsRefused(): void {
    $definition = $this->httpImport([$this->answer([1])]);
    $definition->setStatus(FALSE)->save();
    $state = new FormState();
    $state->addBuildInfo('args', [$definition]);

    $form = $this->container->get('form_builder')->buildForm(RunStartForm::class, $state);

    $this->assertFalse($form['actions']['submit']['#access']);
    $this->assertStringContainsString('is disabled', $this->messages()['error'][0]);
    $this->assertNull($this->container->get('import_engine.run_manager')->latest('customers'));
  }

  /**
   * A source that is down stops the run, and the run can be continued.
   */
  public function testOutageStopsTheRunAndItContinues(): void {
    $definition = $this->httpImport([new Response(503), $this->answer([1, 2])]);

    $this->submit($definition);

    $message = $this->messages()['warning'][0];
    $this->assertStringContainsString('Run 1 is not over', $message);
    $this->assertStringContainsString('HTTP 503', $message);
    $run = $this->container->get('import_engine.run_manager')->latest('customers');
    $this->assertSame(RunStatus::Extracting, $run?->getStatus());
    $this->assertCount(0, Node::loadMultiple());

    // The same form continues it, now that the source answers.
    $this->submit($definition);

    $this->assertCount(2, Node::loadMultiple());
    $this->assertSame(RunStatus::Completed, $this->container->get('import_engine.run_manager')->latest('customers')?->getStatus());
  }

  /**
   * One call of the batch works for a limited time and shows the way.
   */
  public function testBatchCallsReportProgress(): void {
    $definition = $this->httpImport([$this->answer([1, 2, 3, 4])]);
    $run = $this->container->get('import_engine.run_starter')->start($definition, Trigger::Ui, 1);
    $spent = new RunBatch(
      $this->container->get('import_engine.run_driver'),
      $this->items,
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('messenger'),
      $this->container->get('datetime.time'),
      -1,
    );

    // No time at all: the page is read, nothing is processed.
    $context = ['results' => []];
    $spent->drive((int) $run->id(), $context);

    $this->assertGreaterThan(0, $context['finished']);
    $this->assertLessThan(1, $context['finished']);
    $this->assertSame('processing', $context['results']['status']);
    $this->assertSame(1, $context['results']['pages']);
    $this->assertCount(0, Node::loadMultiple());

    // The next call has its time: it finishes the run, and the totals add up.
    $batch = $this->container->get('import_engine_ui.run_batch');
    $batch->drive((int) $run->id(), $context);
    $this->assertSame(1, $context['finished']);
    $this->assertSame('completed', $context['results']['status']);
    $this->assertSame(4, $context['results']['items']);
    $this->assertCount(4, Node::loadMultiple());
  }

  /**
   * A run that is over, or gone, ends the batch at once.
   */
  public function testBatchEndsForFinishedOrMissingRun(): void {
    $run = $this->importRows($this->pagesOf(1, 1)[0]);
    $this->finishRun($run);
    $batch = $this->container->get('import_engine_ui.run_batch');

    $context = ['results' => []];
    $batch->drive((int) $run->id(), $context);
    $this->assertSame(1, $context['finished']);
    $this->assertSame('completed', $context['results']['status']);

    $context = ['results' => []];
    $batch->drive(9999, $context);
    $this->assertSame(1, $context['finished']);
    $this->assertStringContainsString('no longer exists', $context['results']['stopped']);
  }

  /**
   * Only people who may administer runs can start one.
   */
  public function testAccess(): void {
    $definition = $this->httpImport([]);
    $manager = $this->container->get('access_manager');
    $parameters = ['import_definition' => $definition->id()];

    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.definition_run', $parameters, $this->createUser(['administer import runs'])));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.definition_run', $parameters, $this->createUser(['view import runs'])));
  }

}
