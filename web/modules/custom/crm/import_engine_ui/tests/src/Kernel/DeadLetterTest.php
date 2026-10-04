<?php

declare(strict_types=1);

namespace Drupal\Tests\import_engine_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\import_engine\Storage\EventType;
use Drupal\import_engine\Storage\ItemState;
use Drupal\import_engine_ui\DeadLetter\DeadLetterOperations;
use Drupal\import_engine_ui\Form\DeadLetterEditForm;
use Drupal\import_engine_ui\Form\DeadLetterForm;
use Drupal\node\Entity\Node;
use Drupal\Tests\import_engine\Kernel\NodeTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the dead letter queue pages: listing, actions, editing, access.
 */
#[Group('import_engine_ui')]
#[RunTestsInSeparateProcesses]
class DeadLetterTest extends NodeTestBase {

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
   * The operations service.
   */
  protected DeadLetterOperations $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('router.builder')->rebuild();
    $this->operations = $this->container->get('import_engine_ui.dead_letter');
    $this->setUpCurrentUser(['name' => 'alice'], ['view import runs', 'administer import runs']);
  }

  /**
   * Imports one good and two bad accounts, so two items are dead.
   *
   * @return list<int>
   *   The IDs of the dead items.
   */
  protected function deadItems(): array {
    $this->importRows([
      ['id' => 1, 'name' => 'Acme', 'code' => 'A'],
      ['id' => 2, 'name' => '', 'code' => 'B'],
      ['id' => 3, 'name' => '', 'code' => 'C'],
    ]);
    $items = $this->items->listItems(NULL, ItemState::Dead, 10);
    $this->assertCount(2, $items);
    return array_reverse(array_map(static fn ($item): int => $item->id, $items));
  }

  /**
   * Builds the queue form.
   *
   * @return array<string, mixed>
   *   The form.
   */
  protected function queue(): array {
    return $this->container->get('form_builder')->getForm(DeadLetterForm::class);
  }

  /**
   * Submits the queue form with a button and some items checked.
   *
   * @param string $button
   *   The label of the button.
   * @param list<int> $ids
   *   The items that are checked.
   * @param \Drupal\Core\Form\FormStateInterface|null $form_state
   *   The form state to use; a new one by default.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submit.
   */
  protected function press(string $button, array $ids, ?FormStateInterface $form_state = NULL): FormStateInterface {
    $form_state ??= new FormState();
    $items = array_combine($ids, $ids);
    // The form builder takes the input of a programmed form from the values.
    $form_state->setValues(['op' => $button, 'items' => $items]);
    $builder = $this->container->get('form_builder');
    $builder->submitForm(DeadLetterForm::class, $form_state);
    return $form_state;
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
   * The queue lists the dead items with their errors.
   */
  public function testListsDeadItems(): void {
    $ids = $this->deadItems();

    $form = $this->queue();

    // Newest first.
    $this->assertSame(array_reverse($ids), array_map('intval', array_keys($form['items']['#options'])));
    $row = $form['items']['#options'][$ids[0]];
    $this->assertSame('["2"]', $row['key']);
    $this->assertNotSame('', $row['error']);
    $this->assertTrue($form['actions']['#access']);
  }

  /**
   * The queue can be limited to one import.
   */
  public function testFilterByImport(): void {
    $this->deadItems();
    $stack = $this->container->get('request_stack');

    foreach (['customers' => 2, 'suppliers' => 0] as $import => $count) {
      $request = new Request(['import' => $import]);
      $request->setSession(new Session(new MockArraySessionStorage()));
      $stack->push($request);
      $this->assertCount($count, $this->queue()['items']['#options'], $import);
      $stack->pop();
    }
  }

  /**
   * Without the right to change things there are no buttons.
   */
  public function testViewerHasNoButtons(): void {
    $this->deadItems();
    $this->setUpCurrentUser(['name' => 'bob'], ['view import runs']);

    $form = $this->queue();

    $this->assertFalse($form['actions']['#access']);
    $this->assertArrayHasKey('items', $form);
    $this->assertSame([], $form['items']['#options'][array_key_first($form['items']['#options'])]['operations']['data']);
  }

  /**
   * Retrying makes items pending and writes who did it.
   */
  public function testRetry(): void {
    $ids = $this->deadItems();

    $this->press('Retry selected', [$ids[0]]);

    $this->assertSame(['status' => ['1 items are pending again; a worker handles them.']], $this->messages());
    $this->assertSame(ItemState::Pending, $this->items->find($ids[0])?->state);
    $this->assertSame(ItemState::Dead, $this->items->find($ids[1])?->state);
    $events = array_filter($this->container->get('import_engine.event_log')->history('customers', '["2"]'), static fn ($event): bool => $event->event === EventType::Requeued);
    $this->assertCount(1, $events);
    $this->assertSame('Retried by alice.', reset($events)->message);
  }

  /**
   * Pressing a button with nothing checked warns and does nothing.
   */
  public function testNothingSelected(): void {
    $this->deadItems();

    $this->press('Retry selected', []);

    $this->assertSame(['warning' => ['Select at least one item.']], $this->messages());
  }

  /**
   * Discarding asks first; confirming deletes the items and writes who did it.
   */
  public function testDiscardNeedsConfirmation(): void {
    $ids = $this->deadItems();

    $state = $this->press('Discard selected', [$ids[0]]);

    $this->assertSame([$ids[0]], $state->get('confirm_discard'));
    $this->assertNotNull($this->items->find($ids[0]), 'Nothing is deleted before the question is answered.');

    $this->assertSame(1, $this->operations->discard([$ids[0]]));
    $this->assertNull($this->items->find($ids[0]));
    $this->assertNotNull($this->items->find($ids[1]));
    $discarded = array_filter($this->container->get('import_engine.event_log')->history('customers', '["2"]'), static fn ($event): bool => $event->event === EventType::Discarded);
    $this->assertCount(1, $discarded);
    $this->assertSame(['Discarded by alice.'], array_values(array_map(static fn ($event): ?string => $event->message, $discarded)));
  }

  /**
   * Handling items now runs a batch: items are retried and handled at once.
   */
  public function testProcessNowRunsBatch(): void {
    $ids = $this->deadItems();
    // One of the two is fixed by hand, the other stays wrong.
    $this->items->updatePayload($ids[0], ['id' => 2, 'name' => 'Fixed', 'code' => 'B']);

    // A programmed form runs its batch straight away.
    $this->press('Handle selected now', $ids);

    $messages = $this->messages();
    $this->assertStringContainsString('1 created, 0 updated, 0 unchanged, 0 retrying, 1 failed', $messages['status'][0]);
    $this->assertSame(ItemState::Done, $this->items->find($ids[0])?->state);
    $this->assertSame(ItemState::Dead, $this->items->find($ids[1])?->state);
    $this->assertCount(2, array_filter($this->container->get('import_engine.event_log')->forRun(1), static fn ($event): bool => $event->event === EventType::Requeued));
  }

  /**
   * An edited item that is handled now becomes a node.
   */
  public function testEditThenProcessNow(): void {
    $ids = $this->deadItems();
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$ids[0]]);
    $form_state->setValues(['payload' => json_encode(['id' => 2, 'name' => 'Fixed', 'code' => 'B']), 'retry' => NULL]);

    $this->container->get('form_builder')->submitForm(DeadLetterEditForm::class, $form_state);

    $this->assertSame(['status' => ['The payload is saved.']], $this->messages());
    $this->assertSame(ItemState::Dead, $this->items->find($ids[0])?->state);
    $context = [];
    $this->operations->processNow([$ids[0]], $context);
    $this->assertSame(1, $context['results']['created']);
    $this->assertSame(ItemState::Done, $this->items->find($ids[0])?->state);
    $titles = array_map(static fn (Node $node): string => (string) $node->label(), Node::loadMultiple());
    $this->assertContains('Fixed', $titles);
  }

  /**
   * Saving with the retry box ticked makes the item pending.
   */
  public function testEditWithRetry(): void {
    $ids = $this->deadItems();
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$ids[0]]);
    $form_state->setValues(['payload' => '{"id": 2, "name": "Fixed", "code": "B"}', 'retry' => TRUE]);

    $this->container->get('form_builder')->submitForm(DeadLetterEditForm::class, $form_state);

    $this->assertSame(ItemState::Pending, $this->items->find($ids[0])?->state);
  }

  /**
   * A payload that is not a JSON object is refused, and nothing is saved.
   */
  public function testEditValidatesJson(): void {
    $ids = $this->deadItems();
    foreach (['{not json', '[1, 2]', '"text"'] as $payload) {
      $form_state = new FormState();
      $form_state->addBuildInfo('args', [$ids[0]]);
      $form_state->setValues(['payload' => $payload, 'retry' => NULL]);

      $this->container->get('form_builder')->submitForm(DeadLetterEditForm::class, $form_state);

      $this->assertNotEmpty($form_state->getErrors(), $payload);
    }
    $payload = $this->items->find($ids[0])?->payload;
    $this->assertSame('', $payload['name'] ?? NULL);
  }

  /**
   * An item that is done has no payload to edit.
   */
  public function testEditUnknownOrDoneItemIsNotFound(): void {
    $this->deadItems();
    $done = $this->items->listItems(NULL, ItemState::Done, 1)[0]->id;

    foreach ([$done, 99999] as $id) {
      try {
        $form_state = new FormState();
        $form_state->addBuildInfo('args', [$id]);
        $this->container->get('form_builder')->buildForm(DeadLetterEditForm::class, $form_state);
        $this->fail('Expected a NotFoundHttpException.');
      }
      catch (NotFoundHttpException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Who may see the queue and who may edit is decided by the permissions.
   */
  public function testAccess(): void {
    $ids = $this->deadItems();
    $manager = $this->container->get('access_manager');
    $viewer = $this->createUser(['view import runs']);
    $admin = $this->createUser(['administer import runs']);

    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.dead_letter', [], $viewer));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.dead_letter_edit', ['item_id' => $ids[0]], $viewer));
    $this->assertTrue($manager->checkNamedRoute('import_engine_ui.dead_letter_edit', ['item_id' => $ids[0]], $admin));
    $this->assertFalse($manager->checkNamedRoute('import_engine_ui.dead_letter', [], $this->createUser()));
  }

}
