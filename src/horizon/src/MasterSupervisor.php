<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Closure;
use Exception;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\Pausable;
use Hypervel\Horizon\Contracts\Restartable;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Contracts\Terminable;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Throwable;

class MasterSupervisor implements Pausable, Restartable, Terminable
{
    use ListensForSignals;

    /**
     * The name of the master supervisor.
     */
    public string $name;

    /**
     * All of the supervisors managed.
     *
     * @var Collection<int, SupervisorProcess>
     */
    public Collection $supervisors;

    /**
     * Indicates if the master supervisor process is working.
     */
    public bool $working = true;

    /**
     * The output handler.
     */
    public ?Closure $output = null;

    /**
     * The callback to use to resolve master supervisor names.
     */
    public static ?Closure $nameResolver = null;

    /**
     * The random token appended to the master supervisor name.
     */
    protected static ?string $token = null;

    /**
     * The terminal exit status for this master supervisor.
     */
    protected ?int $exitStatus = null;

    /**
     * Create a new master supervisor instance.
     *
     * @param null|string $environment the environment that was used to provision this master supervisor
     */
    public function __construct(
        public ?string $environment = null
    ) {
        $this->name = static::name();
        $this->supervisors = collect();

        $this->output = function () {
        };

        app(HorizonCommandQueue::class)->flush($this->commandQueue());
    }

    /**
     * Get the name for this master supervisor.
     */
    public static function name(): string
    {
        if (! static::$token) {
            static::$token = Str::random(4);
        }

        return static::basename() . '-' . static::$token;
    }

    /**
     * Get the basename for the machine's master supervisors.
     */
    public static function basename(): string
    {
        return static::$nameResolver
                        ? call_user_func(static::$nameResolver)
                        : Str::slug(gethostname());
    }

    /**
     * Use the given callback to resolve master supervisor names.
     *
     * Boot-only. The resolver persists in a static property for the worker
     * lifetime and runs on every master-name lookup.
     */
    public static function determineNameUsing(Closure $callback): void
    {
        static::$nameResolver = $callback;
    }

    /**
     * Terminate all current supervisors and start fresh ones.
     */
    public function restart(): void
    {
        $this->working = true;

        $this->supervisors->each->terminateWithStatus(1);
    }

    /**
     * Pause the supervisors.
     */
    public function pause(): void
    {
        $this->working = false;

        $this->supervisors->each->pause();
    }

    /**
     * Instruct the supervisors to continue working.
     */
    public function continue(): void
    {
        $this->working = true;

        $this->supervisors->each->continue();
    }

    /**
     * Terminate this master supervisor and all of its supervisors.
     */
    public function terminate(int $status = 0): void
    {
        $this->working = false;

        // First we will terminate all child supervisors so they will gracefully scale
        // down to zero. We'll also grab the longest expiration times of any of the
        // active supervisors so we know the maximum amount of time to wait here.
        $longest = app(SupervisorRepository::class)
            ->longestActiveTimeout();

        $this->supervisors->each->terminate();

        // We will go ahead and remove this master supervisor's record from storage so
        // another master supervisor could get started in its place without waiting
        // for it to really finish terminating all of its underlying supervisors.
        app(MasterSupervisorRepository::class)
            ->forget($this->name);

        $startedTerminating = CarbonImmutable::now();

        // Here we will wait until all of the child supervisors finish terminating and
        // then exit the process. We will keep track of a timeout value so that the
        // process does not get stuck in an infinite loop here waiting for these.
        while (($runningSupervisors = $this->supervisors->filter(
            fn (SupervisorProcess $supervisor): bool => $supervisor->isRunning()
        ))->isNotEmpty()) {
            if (CarbonImmutable::now()->subSeconds($longest)
                ->gte($startedTerminating)) {
                $runningSupervisors->each->kill();
                break;
            }

            sleep(1);
        }

        if (config()->boolean('horizon.fast_termination')) {
            app(CacheFactory::class)->store()->forget('horizon:terminate:wait');
        }

        $this->pendingSignals = [];
        $this->exitStatus = $status;
    }

    /**
     * Monitor the worker processes.
     */
    public function monitor(): int
    {
        $this->ensureNoOtherMasterSupervisors();

        $this->listenForSignals();

        $this->persist();

        while (true) {
            sleep(1);

            $this->loop();

            if ($this->exitStatus !== null) {
                return $this->exitStatus;
            }
        }
    }

    /**
     * Ensure that this is the only master supervisor running for this machine.
     *
     * @throws Exception
     */
    public function ensureNoOtherMasterSupervisors(): void
    {
        if (app(MasterSupervisorRepository::class)->find($this->name) !== null) {
            throw new Exception('A master supervisor is already running on this machine.');
        }
    }

    /**
     * Perform a monitor loop.
     */
    public function loop(): void
    {
        try {
            $this->processPendingSignals();

            $this->processPendingCommands();

            if ($this->exitStatus !== null) {
                return;
            }

            if ($this->working) {
                $this->monitorSupervisors();
            }

            $this->persist();

            event(new MasterSupervisorLooped($this));
        } catch (Throwable $e) {
            app(ExceptionHandler::class)->report($e);
        }
    }

    /**
     * Handle any pending commands for the master supervisor.
     */
    protected function processPendingCommands(): void
    {
        foreach (app(HorizonCommandQueue::class)->pending($this->commandQueue()) as $command) {
            if ($this->exitStatus !== null) {
                return;
            }

            app($command->command)->process($this, $command->options);
        }
    }

    /**
     * "Monitor" all of the supervisors.
     */
    protected function monitorSupervisors(): void
    {
        $this->supervisors->each->monitor();

        $this->supervisors = $this->supervisors->reject(
            fn (SupervisorProcess $supervisor): bool => $supervisor->dead
        );
    }

    /**
     * Persist information about the master supervisor instance.
     */
    public function persist(): void
    {
        app(MasterSupervisorRepository::class)->update($this);
    }

    /**
     * Get the process ID for this supervisor.
     */
    public function pid(): int
    {
        return getmypid();
    }

    /**
     * Get the current memory usage (in megabytes).
     */
    public function memoryUsage(): float
    {
        return memory_get_usage() / 1024 / 1024;
    }

    /**
     * Get the name of the command queue for the master supervisor.
     */
    public static function commandQueue(): string
    {
        return 'master:' . static::name();
    }

    /**
     * Get the name of the command queue for the given master supervisor.
     */
    public static function commandQueueFor(?string $name = null): string
    {
        return $name === null || $name === ''
            ? static::commandQueue()
            : 'master:' . $name;
    }

    /**
     * Set the output handler.
     */
    public function handleOutputUsing(Closure $callback): static
    {
        $this->output = $callback;

        return $this;
    }

    /**
     * Handle the given output.
     */
    public function output(string $type, string $line): void
    {
        call_user_func($this->output, $type, $line);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$nameResolver = null;
        static::$token = null;
    }
}
