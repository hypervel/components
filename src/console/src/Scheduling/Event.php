<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use Carbon\Carbon;
use Closure;
use Cron\CronExpression;
use DateTimeInterface;
use DateTimeZone;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\ClientInterface as HttpClientInterface;
use GuzzleHttp\Exception\TransferException;
use Hypervel\Support\Arr;
use Hyperf\Macroable\Macroable;
use Hyperf\Stringable\Stringable;
use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\Tappable\Tappable;
use Hypervel\Contracts\Console\EventMutex;
use Hypervel\Contracts\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Mail\Mailer;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Traits\ReflectsClosures;
use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
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
    public ?Carbon $lastChecked = null;

    /**
     * The exit status code of the command.
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
     * Create a new event instance.
     *
     * @param EventMutex $mutex the event mutex implementation
     * @param string $command the command string
     */
    public function __construct(
        public EventMutex $mutex,
        public ?string $command,
        DateTimeZone|string|null $timezone = null,
        bool $isSystem = false
    ) {
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
        if ($this->shouldSkipDueToOverlapping()) {
            return null;
        }

        $exitCode = $this->start($container);

        $this->writeOutput($container);

        $this->finish($container, $exitCode);

        return null;
    }

    /**
     * Determine if the event should skip because another process is overlapping.
     */
    public function shouldSkipDueToOverlapping(): bool
    {
        return $this->withoutOverlapping
            && ! $this->mutex->create($this);
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
        return $this->lastChecked?->diffInSeconds() >= ($this->repeatSeconds ?? 60);
    }

    /**
     * Run the command process.
     *
     * @throws Throwable
     */
    protected function start(Container $container): int
    {
        try {
            $this->callBeforeCallbacks($container);

            return $this->execute($container);
        } catch (Throwable $exception) {
            $this->removeMutex();

            throw $exception;
        }
    }

    /**
     * Run the command process.
     */
    protected function execute(Container $container): int
    {
        if ($this->isSystem) {
            return $this->runProcess($container);
        }

        return $container->get(KernelContract::class)
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

        Context::set("scheduling_process:{$this->mutexName()}", $process);

        return $process->run();
    }

    /**
     * Get the output of the system command process.
     */
    protected function getProcessOutput(): ?string
    {
        if (! $process = Context::get("scheduling_process:{$this->mutexName()}")) {
            return null;
        }

        return $process->getOutput();
    }

    /**
     * Mark the command process as finished and run callbacks/cleanup.
     */
    public function finish(Container $container, int $exitCode): void
    {
        $this->exitCode = (int) $exitCode;

        try {
            $this->callAfterCallbacks($container);
        } finally {
            $this->removeMutex();
        }
    }

    /**
     * Call all of the "before" callbacks for the event.
     */
    public function callBeforeCallbacks(Container $container): void
    {
        foreach ($this->beforeCallbacks as $callback) {
            $container->call($callback);
        }
    }

    /**
     * Call all of the "after" callbacks for the event.
     */
    public function callAfterCallbacks(Container $container): void
    {
        foreach ($this->afterCallbacks as $callback) {
            $container->call($callback);
        }
    }

    /**
     * Determine if the given event should run based on the Cron expression.
     */
    public function isDue(ApplicationContract $app): bool
    {
        if ($this->runsInMaintenanceMode()) {
            return false;
        }

        return $this->expressionPasses()
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
     * Determine if the Cron expression passes.
     */
    protected function expressionPasses(): bool
    {
        $date = Date::now();

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
            if (! $app->call($callback)) {
                return false;
            }
        }

        foreach ($this->rejects as $callback) {
            if ($app->call($callback)) {
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
        $filesystem = $container->get(Filesystem::class);
        if (! $this->ensureOutputIsBeingCaptured
            && (! $this->output || (! $this->isSystem && ! $filesystem->isFile($this->output)))
        ) {
            return;
        }

        $output = $this->getOutput($container);

        $this->shouldAppendOutput
            ? $filesystem->append($this->output, $output)
            : $filesystem->put($this->output, $output);
    }

    /**
     * Get the output for the event.
     */
    public function getOutput(Container $container): ?string
    {
        if ($this->isSystem) {
            return $this->getProcessOutput();
        }

        return $container->get(KernelContract::class)->output();
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

        return $this->then(function (Mailer $mailer) use ($addresses, $onlyIfOutputExists) {
            $this->emailOutput($mailer, $addresses, $onlyIfOutputExists);
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

        return $this->onFailure(function (Mailer $mailer) use ($addresses) {
            $this->emailOutput($mailer, $addresses, false);
        });
    }

    /**
     * Ensure that the command output is being captured.
     */
    protected function ensureOutputIsBeingCaptured(): void
    {
        if (is_null($this->output)) {
            $this->ensureOutputIsBeingCaptured = true;
            $this->sendOutputTo(storage_path('logs/schedule-' . sha1($this->mutexName()) . '.log'));
        }
    }

    /**
     * E-mail the output of the event to the recipients.
     */
    protected function emailOutput(Mailer $mailer, mixed $addresses, bool $onlyIfOutputExists = true): void
    {
        $text = is_file($this->output) ? file_get_contents($this->output) : '';

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
                $container->get(ExceptionHandler::class)->report($e);
            }
        };
    }

    /**
     * Get the Guzzle HTTP client to use to send pings.
     */
    protected function getHttpClient(Container $container): ClientInterface
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
            if ($this->exitCode === 0) {
                $container->call($callback);
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
            if ($this->exitCode !== 0) {
                $container->call($callback);
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
            $output = $this->output && is_file($this->output) ? file_get_contents($this->output) : '';

            return $onlyIfOutputExists && empty($output)
                ? null
                : $container->call($callback, ['output' => new Stringable($output)]);
        };
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
    public function nextRunDate(DateTimeInterface|string $currentTime = 'now', int $nth = 0, bool $allowCurrentDate = false): Carbon
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
            . sha1($this->expression . ($this->command ?? ''));
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
     * Delete the mutex for the event.
     */
    protected function removeMutex(): void
    {
        if ($this->withoutOverlapping) {
            $this->mutex->forget($this);
        }
    }
}
