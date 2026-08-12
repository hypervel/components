<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use Carbon\CarbonInterface;
use Closure;
use Cron\CronExpression;
use DateTimeInterface;
use DateTimeZone;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use GuzzleHttp\Exception\TransferException;
use Hypervel\Console\Application;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Mail\Mailer;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Stringable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\ReflectsClosures;
use Hypervel\Support\Traits\Tappable;
use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class Event
{
    use Macroable;
    use ManagesAttributes;
    use ManagesFrequencies;
    use ReflectsClosures;
    use Tappable;

    /**
     * Context key prefix for the current run's exit code.
     */
    protected const EXIT_CODE_CONTEXT_KEY_PREFIX = '__console.scheduling_exit_code.';

    /**
     * Context key prefix for the current run's process.
     */
    protected const PROCESS_CONTEXT_KEY_PREFIX = '__console.scheduling_process.';

    /**
     * Context key prefix for the current run's overlap skip state.
     */
    protected const SKIPPED_BECAUSE_OVERLAPPING_CONTEXT_KEY_PREFIX = '__console.scheduling_skipped_because_overlapping.';

    /**
     * The command string.
     */
    public ?string $command = null;

    /**
     * The location that output should be sent to.
     */
    public ?string $output = null;

    /**
     * Indicates whether output should be appended.
     */
    public bool $shouldAppendOutput = false;

    /**
     * The array of callbacks to be run before the event is started.
     */
    protected array $beforeCallbacks = [];

    /**
     * The array of callbacks to be run after the event is finished.
     */
    protected array $afterCallbacks = [];

    /**
     * The mutex name resolver callback.
     */
    public ?Closure $mutexNameResolver = null;

    /**
     * The last time the event was checked for eligibility to run.
     *
     * Utilized by sub-minute repeated events.
     */
    public ?CarbonInterface $lastChecked = null;

    /**
     * The exit status code of the command.
     *
     * Compatibility snapshot of the last completed run. Use exitCode() inside
     * callbacks so overlapping repeat/background runs read their own result.
     */
    public ?int $exitCode = null;

    /**
     * Determines if the event is system command.
     */
    public bool $isSystem = false;

    /**
     * Determines if output should be captured.
     */
    protected bool $ensureOutputIsBeingCaptured = false;

    /**
     * Indicates whether the execution was skipped due to the mutex already being reserved.
     *
     * Compatibility snapshot of the most recently updated run. Use
     * wasSkippedDueToOverlapping() while handling an active event run.
     */
    public bool $skippedBecauseOverlapping = false;

    /**
     * Indicates whether this event currently owns the overlapping mutex.
     */
    protected bool $mutexAcquired = false;

    /**
     * Create a new event instance.
     *
     * @param EventMutex $mutex the event mutex implementation
     * @param string $command the command string
     */
    public function __construct(
        public EventMutex $mutex,
        ?string $command = null,
        DateTimeZone|string|null $timezone = null,
        bool $isSystem = false
    ) {
        $this->command = $command;
        $this->timezone = $timezone;
        $this->isSystem = $isSystem;
    }

    /**
     * Run the given event.
     *
     * @throws Throwable
     */
    public function run(Container $container): mixed
    {
        CoroutineContext::set(
            $this->skippedBecauseOverlappingContextKey(),
            $this->skippedBecauseOverlapping = false
        );

        if ($this->shouldSkipDueToOverlapping()) {
            CoroutineContext::set(
                $this->skippedBecauseOverlappingContextKey(),
                $this->skippedBecauseOverlapping = true
            );

            return null;
        }

        $exception = null;
        $exitCode = null;

        try {
            $exitCode = $this->start($container);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        if ($exitCode === null) {
            try {
                $this->removeMutex();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }

            CoroutineContext::forget($this->processContextKey());
        } else {
            try {
                $this->writeOutput($container);
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }

            try {
                $this->finish($container, $exitCode);
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return null;
    }

    /**
     * Determine if the event should skip because another process is overlapping.
     */
    public function shouldSkipDueToOverlapping(): bool
    {
        if (! $this->withoutOverlapping) {
            return false;
        }

        if ($this->mutex->create($this)) {
            $this->mutexAcquired = true;

            return false;
        }

        return true;
    }

    /**
     * Determine if the event has been configured to repeat multiple times per minute.
     */
    public function isRepeatable(): bool
    {
        return ! is_null($this->repeatSeconds);
    }

    /**
     * Determine if the event is ready to repeat.
     */
    public function shouldRepeatNow(): bool
    {
        return $this->isRepeatable()
            && $this->lastChecked !== null
            && abs($this->lastChecked->diffInSeconds()) >= $this->repeatSeconds;
    }

    /**
     * Run the command process.
     *
     * @throws Throwable
     */
    protected function start(Container $container): int
    {
        $this->callBeforeCallbacks($container);

        return $this->execute($container);
    }

    /**
     * Run the command process.
     */
    protected function execute(Container $container): int
    {
        if ($this->isSystem) {
            return $this->runProcess($container);
        }

        return $container->make(KernelContract::class)
            ->call($this->command);
    }

    /**
     * Run the system command process.
     */
    protected function runProcess(Container $container): int
    {
        /** @var \Hypervel\Contracts\Foundation\Application $container */
        $process = Process::fromShellCommandline(
            $this->command,
            $container->basePath()
        );

        CoroutineContext::set($this->processContextKey(), $process);

        return $process->run();
    }

    /**
     * Get the output of the system command process.
     */
    protected function getProcessOutput(): ?string
    {
        if (! $process = CoroutineContext::get($this->processContextKey())) {
            return null;
        }

        return $process->getOutput();
    }

    /**
     * Mark the command process as finished and run callbacks/cleanup.
     */
    public function finish(Container $container, int $exitCode): void
    {
        $exception = null;

        try {
            $this->setExitCode($exitCode);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        try {
            $this->callAfterCallbacks($container);
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        try {
            $this->removeMutex();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        CoroutineContext::forget($this->processContextKey());

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Call all of the "before" callbacks for the event.
     */
    public function callBeforeCallbacks(Container $container): void
    {
        foreach ($this->beforeCallbacks as $callback) {
            $this->callEventCallback($container, $callback);
        }
    }

    /**
     * Call all of the "after" callbacks for the event.
     */
    public function callAfterCallbacks(Container $container): void
    {
        foreach ($this->afterCallbacks as $callback) {
            $this->callEventCallback($container, $callback);
        }
    }

    /**
     * Determine if the given event should run based on the Cron expression.
     */
    public function isDue(ApplicationContract $app): bool
    {
        return $this->isDueAt($app, Date::now());
    }

    /**
     * Determine if the given event should run based on the Cron expression at the given time.
     */
    public function isDueAt(ApplicationContract $app, DateTimeInterface $time): bool
    {
        if (! $this->runsInMaintenanceMode() && $app->isDownForMaintenance()) {
            return false;
        }

        return $this->expressionPasses($time)
            && $this->runsInEnvironment($app->environment());
    }

    /**
     * Determine if the event runs in maintenance mode.
     */
    public function runsInMaintenanceMode(): bool
    {
        return $this->evenInMaintenanceMode;
    }

    /**
     * Determine if the event runs when the scheduler is paused.
     */
    public function runsWhenPaused(): bool
    {
        return $this->evenWhenPaused;
    }

    /**
     * Determine if the Cron expression passes.
     */
    protected function expressionPasses(DateTimeInterface $time): bool
    {
        $date = Date::instance($time);

        if ($this->timezone) {
            $date = $date->setTimezone($this->timezone);
        }

        return (new CronExpression($this->expression))
            ->isDue($date->toDateTimeString());
    }

    /**
     * Determine if the event runs in the given environment.
     */
    public function runsInEnvironment(string $environment): bool
    {
        return empty($this->environments)
            || in_array($environment, $this->environments);
    }

    /**
     * Determine if the filters pass for the event.
     */
    public function filtersPass(ApplicationContract $app): bool
    {
        $this->lastChecked = Date::now();

        foreach ($this->filters as $callback) {
            if (! $this->callEventCallback($app, $callback)) {
                return false;
            }
        }

        foreach ($this->rejects as $callback) {
            if ($this->callEventCallback($app, $callback)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensure that the output is stored on disk in a log file.
     */
    public function storeOutput(): static
    {
        $this->ensureOutputIsBeingCaptured();

        return $this;
    }

    /**
     * Send the output of the command to a given location.
     */
    public function sendOutputTo(string $location, bool $append = false): static
    {
        $this->output = $location;

        $this->shouldAppendOutput = $append;

        return $this;
    }

    /**
     * Write the output of the command to the destination file.
     */
    public function writeOutput(Container $container): void
    {
        $filesystem = $container->make(Filesystem::class);
        if (! $this->ensureOutputIsBeingCaptured
            && (! $this->output || (! $this->isSystem && ! $filesystem->isFile($this->output)))
        ) {
            return;
        }

        $output = (string) $this->getOutput($container);

        $written = $this->shouldAppendOutput
            ? $filesystem->append($this->output, $output)
            : $filesystem->put($this->output, $output);

        if ($written !== strlen($output)) {
            throw new RuntimeException("Unable to write the scheduled event output to [{$this->output}].");
        }
    }

    /**
     * Get the output for the event.
     */
    public function getOutput(Container $container): ?string
    {
        if ($this->isSystem) {
            return $this->getProcessOutput();
        }

        return $container->make(KernelContract::class)->output();
    }

    /**
     * Append the output of the command to a given location.
     */
    public function appendOutputTo(string $location): static
    {
        return $this->sendOutputTo($location, true);
    }

    /**
     * E-mail the results of the scheduled operation.
     *
     * @throws LogicException
     */
    public function emailOutputTo(mixed $addresses, bool $onlyIfOutputExists = true): static
    {
        $this->ensureOutputIsBeingCaptured();

        $addresses = Arr::wrap($addresses);

        return $this->then(function (Mailer $mailer, Filesystem $filesystem) use ($addresses, $onlyIfOutputExists) {
            $this->emailOutput($mailer, $filesystem, $addresses, $onlyIfOutputExists);
        });
    }

    /**
     * E-mail the results of the scheduled operation if it produces output.
     *
     * @param array|mixed $addresses
     *
     * @throws LogicException
     */
    public function emailWrittenOutputTo(mixed $addresses): static
    {
        return $this->emailOutputTo($addresses, true);
    }

    /**
     * E-mail the results of the scheduled operation if it fails.
     *
     * @param array|mixed $addresses
     */
    public function emailOutputOnFailure(mixed $addresses): static
    {
        $this->ensureOutputIsBeingCaptured();

        $addresses = Arr::wrap($addresses);

        return $this->onFailure(function (Mailer $mailer, Filesystem $filesystem) use ($addresses) {
            $this->emailOutput($mailer, $filesystem, $addresses, false);
        });
    }

    /**
     * Ensure that the command output is being captured.
     */
    protected function ensureOutputIsBeingCaptured(): void
    {
        if (is_null($this->output)) {
            $this->ensureOutputIsBeingCaptured = true;
            $this->sendOutputTo(storage_path('logs/schedule-' . hash('xxh128', $this->mutexName()) . '.log'));
        }
    }

    /**
     * E-mail the output of the event to the recipients.
     */
    protected function emailOutput(
        Mailer $mailer,
        Filesystem $filesystem,
        mixed $addresses,
        bool $onlyIfOutputExists = true
    ): void {
        $text = $this->readOutput($filesystem);

        if ($onlyIfOutputExists && empty($text)) {
            return;
        }

        $mailer->raw($text, function ($m) use ($addresses) {
            $m->to($addresses)->subject($this->getEmailSubject());
        });
    }

    /**
     * Get the e-mail subject line for output results.
     */
    protected function getEmailSubject(): string
    {
        if ($this->description) {
            return $this->description;
        }

        return "Scheduled Job Output For [{$this->command}]";
    }

    /**
     * Register a callback to ping a given URL before the job runs.
     */
    public function pingBefore(string $url): static
    {
        return $this->before($this->pingCallback($url));
    }

    /**
     * Register a callback to ping a given URL before the job runs if the given condition is true.
     */
    public function pingBeforeIf(bool $value, string $url): static
    {
        return $value ? $this->pingBefore($url) : $this;
    }

    /**
     * Register a callback to ping a given URL after the job runs.
     */
    public function thenPing(string $url): static
    {
        return $this->then($this->pingCallback($url));
    }

    /**
     * Register a callback to ping a given URL after the job runs if the given condition is true.
     */
    public function thenPingIf(bool $value, string $url): static
    {
        return $value ? $this->thenPing($url) : $this;
    }

    /**
     * Register a callback to ping a given URL if the operation succeeds.
     */
    public function pingOnSuccess(string $url): static
    {
        return $this->onSuccess($this->pingCallback($url));
    }

    /**
     * Register a callback to ping a given URL if the operation succeeds and if the given condition is true.
     */
    public function pingOnSuccessIf(bool $value, string $url): static
    {
        return $value ? $this->onSuccess($this->pingCallback($url)) : $this;
    }

    /**
     * Register a callback to ping a given URL if the operation fails.
     */
    public function pingOnFailure(string $url): static
    {
        return $this->onFailure($this->pingCallback($url));
    }

    /**
     * Register a callback to ping a given URL if the operation fails and if the given condition is true.
     */
    public function pingOnFailureIf(bool $value, string $url): static
    {
        return $value ? $this->onFailure($this->pingCallback($url)) : $this;
    }

    /**
     * Get the callback that pings the given URL.
     */
    protected function pingCallback(string $url): Closure
    {
        return function (Container $container) use ($url) {
            try {
                $this->getHttpClient($container)->request('GET', $url);
            } catch (ClientExceptionInterface|TransferException $e) {
                $container->make(ExceptionHandler::class)->report($e);
            }
        };
    }

    /**
     * Get the Guzzle HTTP client to use to send pings.
     */
    protected function getHttpClient(Container $container): HttpClientInterface
    {
        return match (true) {
            $container->bound(HttpClientInterface::class) => $container->make(HttpClientInterface::class),
            $container->bound(HttpClient::class) => $container->make(HttpClient::class),
            default => new HttpClient([
                'connect_timeout' => 10,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                'timeout' => 30,
            ]),
        };
    }

    /**
     * Register a callback to be called before the operation.
     */
    public function before(Closure $callback): static
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to be called after the operation.
     */
    public function after(Closure $callback): static
    {
        return $this->then($callback);
    }

    /**
     * Register a callback to be called after the operation.
     */
    public function then(Closure $callback): static
    {
        $parameters = $this->closureParameterTypes($callback);

        if (Arr::get($parameters, 'output') === Stringable::class) {
            return $this->thenWithOutput($callback);
        }

        $this->afterCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback that uses the output after the job runs.
     */
    public function thenWithOutput(Closure $callback, bool $onlyIfOutputExists = false): static
    {
        $this->ensureOutputIsBeingCaptured();

        return $this->then($this->withOutputCallback($callback, $onlyIfOutputExists));
    }

    /**
     * Register a callback to be called if the operation succeeds.
     */
    public function onSuccess(Closure $callback): static
    {
        $parameters = $this->closureParameterTypes($callback);

        if (Arr::get($parameters, 'output') === Stringable::class) {
            return $this->onSuccessWithOutput($callback);
        }

        return $this->then(function (Container $container) use ($callback) {
            if ($this->exitCode() === 0) {
                $this->callEventCallback($container, $callback);
            }
        });
    }

    /**
     * Register a callback that uses the output if the operation succeeds.
     */
    public function onSuccessWithOutput(Closure $callback, bool $onlyIfOutputExists = false): static
    {
        $this->ensureOutputIsBeingCaptured();

        return $this->onSuccess($this->withOutputCallback($callback, $onlyIfOutputExists));
    }

    /**
     * Register a callback to be called if the operation fails.
     */
    public function onFailure(Closure $callback): static
    {
        $parameters = $this->closureParameterTypes($callback);

        if (Arr::get($parameters, 'output') === Stringable::class) {
            return $this->onFailureWithOutput($callback);
        }

        return $this->then(function (Container $container) use ($callback) {
            if ($this->exitCode() !== 0) {
                $this->callEventCallback($container, $callback);
            }
        });
    }

    /**
     * Register a callback that uses the output if the operation fails.
     */
    public function onFailureWithOutput(Closure $callback, bool $onlyIfOutputExists = false): static
    {
        $this->ensureOutputIsBeingCaptured();

        return $this->onFailure($this->withOutputCallback($callback, $onlyIfOutputExists));
    }

    /**
     * Get a callback that provides output.
     */
    protected function withOutputCallback(Closure $callback, bool $onlyIfOutputExists = false): Closure
    {
        return function (Container $container) use ($callback, $onlyIfOutputExists) {
            $output = $this->readOutput($container->make(Filesystem::class));

            return $onlyIfOutputExists && empty($output)
                ? null
                : $this->callEventCallback($container, $callback, ['output' => new Stringable($output)]);
        };
    }

    /**
     * Read the captured event output.
     */
    protected function readOutput(Filesystem $filesystem): string
    {
        if (! $this->output || ! $filesystem->isFile($this->output)) {
            return '';
        }

        return $filesystem->get($this->output);
    }

    /**
     * Call the given event callback.
     *
     * @param array<string, mixed> $parameters
     */
    protected function callEventCallback(Container $container, callable $callback, array $parameters = []): mixed
    {
        $eventParameters = $callback instanceof Closure
            ? $this->eventParametersForCallback($callback)
            : [];

        return $container->call($callback, array_merge(
            $eventParameters,
            $parameters
        ));
    }

    /**
     * Get the event parameters for the given callback.
     *
     * @return array<string, static>
     */
    protected function eventParametersForCallback(Closure $callback): array
    {
        $parameters = $this->closureParameterTypes($callback);

        foreach ($parameters as $name => $type) {
            if ($type !== null && is_a($this, $type)) {
                return [$name => $this];
            }
        }

        return [];
    }

    /**
     * Get the summary of the event for display.
     */
    public function getSummaryForDisplay(): string
    {
        if (is_string($this->description)) {
            return $this->description;
        }

        return $this->command;
    }

    /**
     * Determine the next due date for an event.
     */
    public function nextRunDate(DateTimeInterface|string $currentTime = 'now', int $nth = 0, bool $allowCurrentDate = false): CarbonInterface
    {
        return Date::instance((new CronExpression($this->getExpression()))
            ->getNextRunDate($currentTime, $nth, $allowCurrentDate, $this->timezone));
    }

    /**
     * Get the Cron expression for the event.
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Set the event mutex implementation to be used.
     */
    public function preventOverlapsUsing(EventMutex $mutex): static
    {
        $this->mutex = $mutex;

        return $this;
    }

    /**
     * Get the mutex name for the scheduled command.
     */
    public function mutexName(): string
    {
        $mutexNameResolver = $this->mutexNameResolver;

        if (! is_null($mutexNameResolver)) {
            return $mutexNameResolver($this);
        }

        return 'framework' . DIRECTORY_SEPARATOR . 'schedule-'
            . hash('xxh128', $this->expression . static::normalizeCommand($this->command ?? ''));
    }

    /**
     * Set the mutex name or name resolver callback.
     */
    public function createMutexNameUsing(Closure|string $mutexName): static
    {
        $this->mutexNameResolver = is_string($mutexName)
            ? fn () => $mutexName
            : $mutexName;

        return $this;
    }

    /**
     * Release the event mutex if this process owns it during signal termination.
     */
    public function releaseMutexOnTerminationSignal(): void
    {
        if ($this->releaseOnTerminationSignals && $this->mutexAcquired) {
            $this->removeMutex();
        }
    }

    /**
     * Delete the mutex for the event.
     */
    protected function removeMutex(): void
    {
        if ($this->withoutOverlapping && $this->mutexAcquired) {
            try {
                $this->mutex->forget($this);
            } finally {
                $this->mutexAcquired = false;
            }
        }
    }

    /**
     * Set the exit code for the current event run.
     */
    protected function setExitCode(int $exitCode): void
    {
        $this->exitCode = $exitCode;

        // The public property is kept for compatibility, but onSuccess/onFailure
        // must read the coroutine-local value when repeat/background runs overlap.
        CoroutineContext::set($this->exitCodeContextKey(), $exitCode);
    }

    /**
     * Get the exit code for the current event run.
     */
    public function exitCode(): ?int
    {
        return CoroutineContext::get($this->exitCodeContextKey(), $this->exitCode);
    }

    /**
     * Determine if this event's most recent run in the current coroutine was skipped due to overlapping.
     */
    public function wasSkippedDueToOverlapping(): bool
    {
        return CoroutineContext::get(
            $this->skippedBecauseOverlappingContextKey(),
            $this->skippedBecauseOverlapping
        );
    }

    /**
     * Get the context key for this event's current run.
     */
    protected function exitCodeContextKey(): string
    {
        return self::EXIT_CODE_CONTEXT_KEY_PREFIX . spl_object_id($this);
    }

    /**
     * Get the context key for this event's current process.
     */
    protected function processContextKey(): string
    {
        return self::PROCESS_CONTEXT_KEY_PREFIX . spl_object_id($this);
    }

    /**
     * Get the context key for this event's overlap skip state.
     */
    protected function skippedBecauseOverlappingContextKey(): string
    {
        return self::SKIPPED_BECAUSE_OVERLAPPING_CONTEXT_KEY_PREFIX . spl_object_id($this);
    }

    /**
     * Format the given command string with a normalized PHP binary path.
     */
    public static function normalizeCommand(string $command): string
    {
        return str_replace([
            Application::phpBinary(),
            Application::artisanBinary(),
        ], [
            'php',
            preg_replace("#['\"]#", '', Application::artisanBinary()),
        ], $command);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
