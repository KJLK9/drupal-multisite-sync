<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Guarantee;

use Drupal\import_engine\Entity\ImportRun;
use Drupal\import_engine\Drive\RunBudget;
use Drupal\import_engine\Entity\ImportDefinition;
use Drupal\import_engine\Run\RunStatus;
use Drupal\import_engine\Run\Trigger;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What must never be written down or sent where it should not.
 */
#[Group('guarantee')]
#[RunTestsInSeparateProcesses]
class SecurityGuaranteeTest extends GuaranteeTestBase {

  /**
   * The key the server wants, and the variable that holds it.
   */
  private const KEY = 'k3y-that-must-stay-secret-9f2b';

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('SITE_A_API_KEY');
    parent::tearDown();
  }

  /**
   * Saves an import that logs in with the key of the environment.
   */
  protected function keyedImport(): ImportDefinition {
    return $this->saveImport([
      'source_key' => ['id'],
      'pagination' => ['plugin' => 'none', 'configuration' => []],
      'authentication' => [
        'plugin' => 'api_key_header',
        'configuration' => ['header' => 'api-key', 'env_var' => 'SITE_A_API_KEY'],
      ],
      'source' => [
        'plugin' => 'http',
        'configuration' => [
          'url' => FakeApi::URL,
          'method' => 'GET',
          'headers' => [],
          'query' => [],
          'body' => '',
          'items_path' => 'data',
          'timeout' => 10,
          'format' => 'json',
          'csv_delimiter' => ',',
        ],
      ],
    ]);
  }

  /**
   * Runs the import against the API.
   */
  protected function runAgainst(FakeApi $api, ImportDefinition $definition): ImportRun {
    $this->container->set('http_client', $api->client());
    $run = $this->starter->start($definition, Trigger::Drush);
    $this->driver->drive($run, $definition, new RunBudget(), 'w', 50);
    return $this->reload($run);
  }

  /**
   * The key goes to the server in the header and is stored nowhere.
   */
  public function testTheKeyIsSentAndNeverStored(): void {
    putenv('SITE_A_API_KEY=' . self::KEY);
    $definition = $this->keyedImport();
    $api = new FakeApi($this->accountItems(1, 5), 'none', 10, TRUE, NULL, self::KEY);

    $run = $this->runAgainst($api, $definition);

    $this->assertSame(RunStatus::Completed, $run->getStatus());
    $this->assertCount(5, Node::loadMultiple());
    $this->assertSame(self::KEY, $api->requests[0]['headers']['api-key'], 'The server got the key.');
    $this->assertStringNotContainsString(self::KEY, $this->writtenDown($run));
    $stored = json_encode($this->container->get('config.factory')->get('import_engine.import_definition.customers')->getRawData(), JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString(self::KEY, $stored, 'The configuration holds the name of the variable, not the key.');
    $this->assertStringContainsString('SITE_A_API_KEY', $stored);
  }

  /**
   * A server that refuses the key fails the run without echoing the key.
   */
  public function testRefusedKeyIsNotEchoed(): void {
    putenv('SITE_A_API_KEY=' . self::KEY);
    $definition = $this->keyedImport();
    $api = new FakeApi($this->accountItems(1, 5), 'none', 10, TRUE, NULL, 'another-key');

    $run = $this->runAgainst($api, $definition);

    $this->assertSame(RunStatus::Failed, $run->getStatus());
    $this->assertCount(0, Node::loadMultiple());
    $this->assertStringNotContainsString(self::KEY, $this->writtenDown($run));
    $this->assertStringNotContainsString(self::KEY, $run->getSummary());
  }

  /**
   * Without the variable there is no request at all.
   */
  public function testMissingKeyFailsBeforeAnyRequest(): void {
    putenv('SITE_A_API_KEY');
    $definition = $this->keyedImport();
    $api = new FakeApi($this->accountItems(1, 5), 'none');

    $run = $this->runAgainst($api, $definition);

    $this->assertNotSame(RunStatus::Completed, $run->getStatus());
    $this->assertSame([], $api->requests, 'The server was not asked without a key.');
  }

  /**
   * A name of a variable that could hold something else is refused.
   */
  public function testOnlyKeyVariablesCanBeNamed(): void {
    putenv('SITE_A_DB_PASSWORD=do-not-leak');
    $definition = $this->saveImport([
      'authentication' => [
        'plugin' => 'api_key_header',
        'configuration' => ['header' => 'api-key', 'env_var' => 'SITE_A_DB_PASSWORD'],
      ],
    ]);

    $violations = $definition->getTypedData()->validate();

    $this->assertGreaterThan(0, $violations->count(), 'The schema refuses a variable that is not a key.');
    putenv('SITE_A_DB_PASSWORD');
  }

}
