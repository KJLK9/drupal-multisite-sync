<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine\Kernel;

use Drupal\import_engine\Source\SourceException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what an HTTP source gives the circuit breaker: its server and a probe.
 */
#[Group('import_engine')]
#[RunTestsInSeparateProcesses]
class HttpSourceEndpointTest extends HttpSourceTestBase {

  /**
   * The server is the host, in lower case, with a port when there is one.
   */
  public function testEndpointIsTheServer(): void {
    $this->assertSame('site-a.test', $this->source(['url' => 'https://Site-A.test/graphql'])->getEndpoint());
    $this->assertSame('site-a.test:8443', $this->source(['url' => 'https://site-a.test:8443/other'])->getEndpoint());
  }

  /**
   * A probe requests the first page and says nothing when it works.
   */
  public function testProbeSucceeds(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([new Response(200, ['Content-Type' => 'application/json'], '{"data":{"customers":{"items":[]}}}')]);

    $this->source()->probe();

    $this->assertCount(1, $this->history);
  }

  /**
   * A probe that gets a server error is transient: the server is still down.
   */
  public function testProbeFailsTransiently(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([new Response(503)]);

    try {
      $this->source()->probe();
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      $this->assertTrue($exception->retryable);
    }
  }

  /**
   * A probe that gets "unauthorized" is permanent: the server is up.
   */
  public function testProbeFailsPermanently(): void {
    putenv(self::ENV_VAR . '=' . self::KEY);
    $this->mockResponses([new Response(401)]);

    try {
      $this->source()->probe();
      $this->fail('Expected a SourceException.');
    }
    catch (SourceException $exception) {
      $this->assertFalse($exception->retryable);
    }
  }

}
