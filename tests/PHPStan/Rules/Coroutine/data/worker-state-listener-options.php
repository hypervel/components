<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateListenerOptions;

use Closure;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Http\Request;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\Queue\Attributes\DebounceFor;

class OptionListener implements ShouldQueue
{
    public static bool $queued = false;

    public ?Request $request = null;

    public string $queue = 'default';

    /** @var array<string, string> */
    public array $queues = [];

    /**
     * Handle the event.
     */
    public function handle(RequestHandled $event): void
    {
    }

    /**
     * Determine whether the listener should be queued, on the container's instance.
     */
    public function shouldQueue(RequestHandled $event): bool
    {
        $this->remember();

        return true;
    }

    /**
     * Get the queue, on the instance the dispatcher makes without the constructor.
     */
    public function viaQueue(RequestHandled $event): string
    {
        self::$queued = true;
        $this->queue = 'reports';
        $this->request = request();
        $this->queues[request()->ip()] = 'reports';
        $this->remember();

        return $this->queue;
    }

    /**
     * Get the job's deduplication ID.
     */
    public function deduplicationId(string $payload, string $queue): string
    {
        self::$queued = true;

        return $queue;
    }

    /**
     * Get the job's unique ID, which the dispatcher reads only from unique listeners.
     */
    public function uniqueId(): string
    {
        self::$queued = true;

        return 'reports';
    }

    /**
     * Remember the current request.
     */
    private function remember(): void
    {
        $this->request = request();
    }
}

class BoundOptionListener implements ShouldQueue
{
    public ?Request $request = null;

    /**
     * Handle the event.
     */
    public function handle(RequestHandled $event): void
    {
    }

    /**
     * Get the queue, on the instance of the registered class the dispatcher makes.
     */
    public function viaQueue(RequestHandled $event): string
    {
        $this->request = request();

        return 'reports';
    }
}

class OverridingOptionListener extends BoundOptionListener
{
    /**
     * Get the queue, which the dispatcher never calls on the bound subclass.
     */
    public function viaQueue(RequestHandled $event): string
    {
        $this->request = request();

        return 'reports';
    }
}

class UniqueOptionListener implements ShouldQueue, ShouldBeUnique
{
    public static bool $locked = false;

    /**
     * Handle the event.
     */
    public function handle(RequestHandled $event): void
    {
    }

    /**
     * Get the job's unique ID.
     */
    public function uniqueId(): string
    {
        self::$locked = true;

        return 'reports';
    }

    /**
     * Get the job's deduplicator, which the dispatcher reads instead of its deduplication ID.
     */
    public function deduplicator(): Closure
    {
        self::$locked = true;

        return static fn (string $payload): string => $payload;
    }

    /**
     * Get the job's deduplication ID.
     */
    public function deduplicationId(string $payload, string $queue): string
    {
        self::$locked = true;

        return $queue;
    }
}

#[DebounceFor(10)]
class DebouncedListener implements ShouldQueue
{
    public static bool $debounced = false;

    /**
     * Handle the event.
     */
    public function handle(RequestHandled $event): void
    {
    }

    /**
     * Get the job's debounce ID.
     */
    public function debounceId(): string
    {
        self::$debounced = true;

        return 'reports';
    }
}

class DebouncedByDefaultListener implements ShouldQueue
{
    public static bool $debounced = false;

    public ?int $debounceFor = 0;

    /**
     * Handle the event.
     */
    public function handle(RequestHandled $event): void
    {
    }

    /**
     * Get the job's debounce ID.
     */
    public function debounceId(): string
    {
        self::$debounced = true;

        return 'reports';
    }
}

class UndebouncedListener implements ShouldQueue
{
    public static bool $debounced = false;

    public ?int $debounceFor = null;

    /**
     * Handle the event.
     */
    public function handle(RequestHandled $event): void
    {
    }

    /**
     * Get the job's debounce ID, which the dispatcher reads only from debounced listeners.
     */
    public function debounceId(): string
    {
        self::$debounced = true;

        return 'reports';
    }
}
