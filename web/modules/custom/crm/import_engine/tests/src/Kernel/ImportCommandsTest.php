<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\Trigger;
use Drupal\import_engine\Drush\Commands\ImportCommands;
use Drupal\import_engine\Run\RunStatus;
use Drupal\node\Entity\Node;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the drush commands: output and exit codes, with a mocked source.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class ImportCommandsTest extends NodeTestBase {

  /**
   * What the command wrote.
   */
  protected BufferedOutput $output;

  /**
   * Builds the commands with their output captured.
   */
  protected function commands(): ImportCommands {
    $commands = new ImportCommands(
      $this->container->get('import_engine.run_driver'),
      $this->container->get('import_engine.worker'),
      $this->container->get('import_engine.run_starter'),
      $this->container->get('import_engine.run_manager'),
      $this->container->get('import_engine.item_storage'),
      $this->container->get('import_engine.breaker_store'),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
    );
    $this->output = new BufferedOutput();
    $commands->setInput(new ArrayInput([]));
    $commands->setOutput($this->output);
    return $commands;
  }

  /**
   * Saves a definition that reads from the mocked source.
   *
   * @param list<\Psr\Http\Message\ResponseInterface> $responses
   *   The answers of the source.
   */
  protected function httpImport(array $responses): void {
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
    $this->definition($this->accounts([
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
  }

  /**
   * Builds a JSON response with accounts.
   *
   * @param list<int> $ids
   *   The account IDs.
   * @param string $name
   *   The name of the accounts; empty makes them invalid.
   */
  protected function answer(array $ids, string $name = 'Account'): Response {
    $rows = array_map(static fn (int $id): array => [
      'id' => $id,
      'name' => $name === '' ? '' : "$name $id",
      'code' => "C$id",
    ], $ids);
    return new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $rows], JSON_THROW_ON_ERROR));
  }

  /**
   * Runs an import from the command line.
   */
  public function testRunCommand(): void {
    $this->httpImport([$this->answer([1, 2, 3])]);
    $commands = $this->commands();

    $result = $commands->run('customers');

    $this->assertSame(0, $result->getExitCode());
    $this->assertStringContainsString('Started run 1.', $this->output->fetch());
    $this->assertCount(3, Node::loadMultiple());
    $this->assertSame(RunStatus::Completed, $this->container->get('import_engine.run_manager')->latest('customers')?->getStatus());
  }

  /**
   * A run with failing items gives exit code 1.
   */
  public function testRunCommandWithErrors(): void {
    $this->httpImport([$this->answer([1], '')]);

    $result = $this->commands()->run('customers');

    $this->assertSame(1, $result->getExitCode());
    $this->assertStringContainsString('completed_with_errors', $this->output->fetch());
  }

  /**
   * A run that is stopped gives exit code 2 and the next call continues it.
   */
  public function testRunCommandContinues(): void {
    $this->httpImport([$this->answer([1, 2]), $this->answer([1, 2])]);
    // A first call that stops early, because its budget is already spent.
    $definition = ImportDefinition::load('customers');
    $this->assertNotNull($definition);
    $run = $this->container->get('import_engine.run_starter')->start($definition, Trigger::Drush);
    $this->container->get('import_engine.run_driver')->drive($run, $definition, new RunBudget(1), 'w');

    $result = $this->commands()->run('customers');

    $this->assertSame(0, $result->getExitCode());
    $this->assertStringContainsString('Continuing run 1 (', $this->output->fetch());
    $this->assertCount(2, Node::loadMultiple());
  }

  /**
   * An unknown or disabled import is refused.
   */
  public function testUnknownImport(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('There is no import "nope".');

    $this->commands()->run('nope');
  }

  /**
   * The worker command handles the queue.
   */
  public function testWorkCommand(): void {
    $this->extractRows($this->pagesOf(1, 3)[0]);

    $result = $this->commands()->work(['pool' => 'default', 'max-time' => 0, 'batch' => 50, 'once' => TRUE]);

    $this->assertSame(0, $result->getExitCode());
    $this->assertStringContainsString('Handled 3 items and finished 1 runs; stopped: idle.', $this->output->fetch());
  }

  /**
   * The status command shows the latest run of every import.
   */
  public function testStatusCommand(): void {
    $this->definition($this->accounts())->save();
    $commands = $this->commands();
    $commands->status();
    $this->assertStringContainsString('never run', $this->output->fetch());

    $this->importRows($this->pagesOf(1, 2)[0]);
    $commands->status('customers');
    $text = $this->output->fetch();
    $this->assertStringContainsString('processing', $text);
    $this->assertStringContainsString('done 2', $text);
  }

  /**
   * The retry and cancel commands report what they did.
   */
  public function testRetryAndCancelCommands(): void {
    $run = $this->importRows([['id' => 1, 'name' => '', 'code' => 'A']]);
    $commands = $this->commands();

    $commands->retry('customers');
    $this->assertStringContainsString('1 dead items are pending again', $this->output->fetch());

    $this->assertSame(0, $commands->cancel((string) $run->id())->getExitCode());
    $this->assertStringContainsString('1 waiting items skipped', $this->output->fetch());
    $this->assertSame(1, $commands->cancel((string) $run->id())->getExitCode());
    $this->assertStringContainsString('already over (cancelled)', $this->output->fetch());
  }

  /**
   * The breaker commands show, open and close a circuit breaker.
   */
  public function testBreakerCommands(): void {
    $commands = $this->commands();
    $commands->breaker();
    $this->assertStringNotContainsString('OPEN', strtoupper($this->output->fetch()));

    $commands->breakerTrip('site-a.test');
    $this->assertStringContainsString('The circuit breaker for site-a.test is open.', $this->output->fetch());
    $commands->breaker();
    $text = $this->output->fetch();
    $this->assertStringContainsString('site-a.test', $text);
    $this->assertStringContainsString('Open (by hand)', $text);

    $commands->breakerReset('site-a.test');
    $this->assertStringContainsString('is closed.', $this->output->fetch());
    $commands->breakerReset('site-a.test');
    $this->assertStringContainsString('was closed already.', $this->output->fetch());
  }

}
