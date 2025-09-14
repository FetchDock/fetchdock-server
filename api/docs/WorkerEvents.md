# Worker Events Documentation

This document describes the event system that allows workers to dispatch events during job processing lifecycle.

## Overview

Workers dispatch events at key points during job processing:

- **Job Picked Up**: When a worker picks up a job for processing
- **Job Update**: When an update occurs while handling the job (e.g., state changes)
- **Job Completed**: When the worker successfully completes a job
- **Job Failed**: When the job fails during processing

All events conform to Symfony's event implementation and extend `Symfony\Contracts\EventDispatcher\Event`.

## Available Events

### 1. JobPickedUpEvent

Dispatched when a worker picks up a job for processing.

```php
use App\Event\JobPickedUpEvent;

// Event properties:
$event->getDownloadJob();      // DownloadJob entity
$event->getWorkerIdentifier(); // Optional worker identifier string
```

### 2. JobUpdateEvent

Dispatched when an update occurs while handling a job.

```php
use App\Event\JobUpdateEvent;

// Event properties:
$event->getDownloadJob();    // DownloadJob entity
$event->getUpdateMessage();  // String describing the update
$event->getContext();        // Optional array of additional context
```

### 3. JobCompletedEvent

Dispatched when a worker successfully completes a job.

```php
use App\Event\JobCompletedEvent;

// Event properties:
$event->getDownloadJob(); // DownloadJob entity
$event->getMetadata();    // Optional array of metadata about completion
```

### 4. JobFailedEvent

Dispatched when a job fails during processing.

```php
use App\Event\JobFailedEvent;

// Event properties:
$event->getDownloadJob(); // DownloadJob entity
$event->getException();   // The exception that caused the failure
$event->getContext();     // Optional array of additional context
```

## Using Events with Listeners

### Event Listener (Attribute-based)

```php
use App\Event\JobCompletedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class MyJobListener
{
    #[AsEventListener(event: JobCompletedEvent::class)]
    public function onJobCompleted(JobCompletedEvent $event): void
    {
        $job = $event->getDownloadJob();
        // Handle the completed job...
    }
}
```

### Event Subscriber

```php
use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MyJobSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            JobCompletedEvent::class => 'onJobCompleted',
            JobFailedEvent::class => 'onJobFailed',
        ];
    }

    public function onJobCompleted(JobCompletedEvent $event): void
    {
        // Handle completed job...
    }

    public function onJobFailed(JobFailedEvent $event): void
    {
        // Handle failed job...
    }
}
```

## Examples

### Logging Events

See `App\EventListener\JobEventLogger` for an example that logs all job events.

### Metrics Collection

```php
use App\Event\JobCompletedEvent;
use App\Event\JobFailedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class MetricsCollector
{
    #[AsEventListener(event: JobCompletedEvent::class)]
    public function onJobCompleted(JobCompletedEvent $event): void
    {
        $this->metrics->increment('jobs.completed');
    }

    #[AsEventListener(event: JobFailedEvent::class)]
    public function onJobFailed(JobFailedEvent $event): void
    {
        $this->metrics->increment('jobs.failed');
    }
}
```

### Notification System

```php
use App\Event\JobFailedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class NotificationService
{
    #[AsEventListener(event: JobFailedEvent::class)]
    public function onJobFailed(JobFailedEvent $event): void
    {
        $job = $event->getDownloadJob();
        $this->sendAlert("Job {$job->getId()} failed: {$event->getException()->getMessage()}");
    }
}
```

## Event Flow

```
1. Worker picks up job
   └── Dispatches JobPickedUpEvent
   
2. Worker processes job
   ├── Dispatches JobUpdateEvent (when state changes)
   └── Either:
       ├── Success: Dispatches JobCompletedEvent
       └── Failure: Dispatches JobFailedEvent
```

## Configuration

Events are automatically available once the system is configured. No additional setup is required beyond creating your event listeners or subscribers.

The event dispatcher is automatically injected into the `DownloadJobHandler` and events are dispatched at the appropriate lifecycle points.