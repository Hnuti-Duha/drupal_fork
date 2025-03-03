<?php

namespace Drupal\performance_test;

use Drupal\Core\Database\Event\StatementExecutionEndEvent;
use Drupal\Core\DestructableInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PerformanceDataCollector implements EventSubscriberInterface, DestructableInterface {

  /**
   * Database events collected during the request.
   *
   * @var Drupal\Core\Database\Event\StatementExecutionEndEvent[]
   */
  protected array $databaseEvents = [];

  /**
   * Cache operations collected during the request.
   */
  protected array $cacheOperations = [];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      StatementExecutionEndEvent::class => 'onStatementExecutionEnd',
    ];
  }

  /**
   * Logs database statements.
   */
  public function onStatementExecutionEnd(StatementExecutionEndEvent $event): void {
    // Use the event object as a value object.
    $this->databaseEvents[] = $event;
  }

  /**
   * Adds a cache operation.
   */
  public function addCacheOperation(array $operation) {
    $this->cacheOperations[] = $operation;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    // Get the events now before issuing any more database queries so that this
    // logging does not become part of the recorded data.
    $database_events = $this->databaseEvents;

    // Deliberately do not use an injected key value or lock service to avoid
    // any overhead up until this point.
    $lock = \Drupal::lock();

    // This loop should be safe because we know a very finite number of requests
    // will be trying to acquire a lock at any one time.
    while (!$lock->acquire('performance_test')) {
      $lock->wait();
    }
    $collection = \Drupal::keyValue('performance_test');
    $existing_data = $collection->get('performance_test_data') ?? [
      'database_events' => [],
      'cache_operations' => [],
    ];
    $existing_data['database_events'] = array_merge($existing_data['database_events'], $database_events);
    $existing_data['cache_operations'] = array_merge($existing_data['cache_operations'], $this->cacheOperations);
    $collection->set('performance_test_data', $existing_data);
    $lock->release('performance_test');
  }

}
