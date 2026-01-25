<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use DateTimeZone;
use Hypervel\Contracts\Console\EventMutex;
use Hypervel\Container\Contracts\Container;
use Hypervel\Support\Reflector;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class CallbackEvent extends Event
{
    /**
     * The callback to call.
     *
     * @var callable|string
     */
    protected $callback;

    /**
     * The parameters to pass to the method.
     */
    protected array $parameters;

    /**
     * The result of the callback's execution.
     */
    protected mixed $result;

    /**
     * The exception that was thrown when calling the callback, if any.
     */
    protected ?Throwable $exception = null;

    /**
     * Create a new event instance.
     *
     * @param callable|string $callback
     * @param null|DateTimeZone|string $timezone
     *
     * @throws InvalidArgumentException
     */
    public function __construct(EventMutex $mutex, $callback, array $parameters = [], $timezone = null)
    {
        if (! is_string($callback) && ! Reflector::isCallable($callback)) {
            throw new InvalidArgumentException(
                'Invalid scheduled callback event. Must be a string or callable.'
            );
        }

        $this->mutex = $mutex;
        $this->callback = $callback;
        $this->parameters = $parameters;
        $this->timezone = $timezone;
    }

    /**
     * Run the callback event.
     *
     * @throws Throwable
     */
    public function run(Container $container): mixed
    {
        parent::run($container);

        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }

    /**
     * Determine if the event should skip because another process is overlapping.
     */
    public function shouldSkipDueToOverlapping(): bool
    {
        return $this->description && parent::shouldSkipDueToOverlapping();
    }

    /**
     * Indicate that the callback should run in the background.
     *
     * @throws RuntimeException
     */
    public function runInBackground(): static
    {
        throw new RuntimeException('Scheduled closures can not be run in the background.');
    }

    /**
     * Run the callback.
     */
    protected function execute(Container $container): int
    {
        try {
            $this->result = is_object($this->callback)
                ? $container->call([$this->callback, '__invoke'], $this->parameters)
                : $container->call($this->callback, $this->parameters);

            return $this->result === false ? 1 : 0;
        } catch (Throwable $e) {
            $this->exception = $e;

            return 1;
        }
    }

    /**
     * Do not allow the event to overlap each other.
     *
     * The expiration time of the underlying cache lock may be specified in minutes.
     *
     * @throws LogicException
     */
    public function withoutOverlapping(int $expiresAt = 1440): static
    {
        if (! isset($this->description)) {
            throw new LogicException(
                "A scheduled event name is required to prevent overlapping. Use the 'name' method before 'withoutOverlapping'."
            );
        }

        return parent::withoutOverlapping($expiresAt);
    }

    /**
     * Allow the event to only run on one server for each cron expression.
     *
     * @throws LogicException
     */
    public function onOneServer(): static
    {
        if (! isset($this->description)) {
            throw new LogicException(
                "A scheduled event name is required to only run on one server. Use the 'name' method before 'onOneServer'."
            );
        }

        return parent::onOneServer();
    }

    /**
     * Get the summary of the event for display.
     */
    public function getSummaryForDisplay(): string
    {
        if (is_string($this->description)) {
            return $this->description;
        }

        return is_string($this->callback) ? $this->callback : 'Callback';
    }

    /**
     * Get the mutex name for the scheduled command.
     */
    public function mutexName(): string
    {
        return 'framework/schedule-' . sha1($this->description ?? '');
    }

    /**
     * Clear the mutex for the event.
     */
    protected function removeMutex(): void
    {
        if ($this->description) {
            parent::removeMutex();
        }
    }
}
