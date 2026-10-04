<?php

declare(strict_types=1);

namespace Drupal\import_engine\Source;

use Drupal\import_engine\ImportDefinitionInterface;
use Drupal\import_engine\Path\PathDiscovery;
use Drupal\import_engine\Path\PathResolver;

/**
 * Tries the source of an import while it is being set up.
 *
 * It reads a few pages (the check of the source plugin), reports what it
 * found, and lists the dotted paths in the sample items, so a person can
 * choose the key and the sources of a mapping from what is really there. The
 * definition does not have to be saved or complete.
 */
final class SourceSampler {

  /**
   * The longest example value, in characters.
   */
  private const EXAMPLE_LENGTH = 40;

  /**
   * Constructs the sampler.
   */
  public function __construct(
    private readonly SourceFactory $sources,
    private readonly PathDiscovery $discovery,
    private readonly PathResolver $paths,
  ) {
  }

  /**
   * Tries the source of a definition.
   *
   * Nothing is thrown: a source that cannot even be created is a message.
   */
  public function sample(ImportDefinitionInterface $definition): SourceSample {
    try {
      $check = $this->sources->create($definition)->check();
    }
    catch (\Throwable $exception) {
      return new SourceSample([
        ['severity' => Severity::Error->value, 'message' => 'The source cannot be used: ' . $exception->getMessage()],
      ], [], 0);
    }

    $messages = [];
    foreach ($check->getMessages() as $entry) {
      $messages[] = ['severity' => $entry['severity']->value, 'message' => $entry['message']];
    }
    $paths = [];
    foreach ($check->sampleItems as $item) {
      foreach ($this->discovery->discover($item) as $path => $type) {
        $paths[$path] ??= ['type' => $type, 'example' => $this->example($item, $path)];
      }
    }
    return new SourceSample($messages, $paths, count($check->sampleItems));
  }

  /**
   * Returns a short text of the value at a path.
   *
   * @param array<string, mixed> $item
   *   The item.
   * @param string $path
   *   The dotted path.
   */
  private function example(array $item, string $path): string {
    $value = $this->paths->get($item, $path);
    $text = match (TRUE) {
      is_array($value) => '[list of ' . count($value) . ']',
      is_bool($value) => $value ? 'true' : 'false',
      $value === NULL => 'null',
      default => (string) $value,
    };
    return mb_strlen($text) > self::EXAMPLE_LENGTH ? mb_substr($text, 0, self::EXAMPLE_LENGTH - 1) . '…' : $text;
  }

}
