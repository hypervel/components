<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use Closure;
use DateTimeZone;
use Hypervel\Support\Reflector;

trait ManagesAttributes
{
    /**
     * The cron expression representing the event's frequency.
     */
    public string $expression = '* * * * *';

    /**
     * How often to repeat the event during a minute.
     */
    public ?int $repeatSeconds = null;

    /**
     * The timezone the date should be evaluated on.
     */
    public null|DateTimeZone|string $timezone = null;

    /**
     * The user the command should run as.
     */
    public ?string $user = null;

    /**
     * The list of environments the command should run under.
     */
    public array $environments = [];

    /**
     * Indicates if the command should run in maintenance mode.
     */
    public bool $evenInMaintenanceMode = false;

    /**
     * Indicates if the command should not overlap itself.
     */
    public bool $withoutOverlapping = false;

    /**
     * Indicates if the command should only be allowed to run on one server for each cron expression.
     */
    public bool $onOneServer = false;

    /**
     * The number of minutes the mutex should be valid.
     */
    public int $expiresAt = 1440;

    /**
     * Indicates if the command should run in the background.
     */
    public bool $runInBackground = false;

    /**
     * The array of filter callbacks.
     */
    protected array $filters = [];

    /**
     * The array of reject callbacks.
     */
    protected array $rejects = [];

    /**
     * The human readable description of the event.
     */
    public ?string $description = null;

    /**
     * Set which user the command should run as.
     */
    public function user(string $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Limit the environments the command should run in.
     *
     * @param array|mixed $environments
     */
    public function environments(mixed $environments): static
    {
        $this->environments = is_array($environments) ? $environments : func_get_args();

        return $this;
    }

    /**
     * State that the command should run even in maintenance mode.
     */
    public function evenInMaintenanceMode(): static
    {
        $this->evenInMaintenanceMode = true;

        return $this;
    }

    /**
     * Do not allow the event to overlap each other.
     * The expiration time of the underlying cache lock may be specified in minutes.
     */
    public function withoutOverlapping(int $expiresAt = 1440): static
    {
        $this->withoutOverlapping = true;

        $this->expiresAt = $expiresAt;

        return $this->skip(function () {
            return $this->mutex->exists($this);
        });
    }

    /**
     * Allow the event to only run on one server for each cron expression.
     */
    public function onOneServer(): static
    {
        $this->onOneServer = true;

        return $this;
    }

    /**
     * State that the command should run in the background.
     */
    public function runInBackground(): static
    {
        $this->runInBackground = true;

        return $this;
    }

    /**
     * Register a callback to further filter the schedule.
     */
    public function when(bool|Closure $callback): static
    {
        $this->filters[] = Reflector::isCallable($callback) ? $callback : function () use ($callback) {
            return $callback;
        };

        return $this;
    }

    /**
     * Register a callback to further filter the schedule.
     */
    public function skip(bool|Closure $callback): static
    {
        $this->rejects[] = Reflector::isCallable($callback) ? $callback : function () use ($callback) {
            return $callback;
        };

        return $this;
    }

    /**
     * Set the human-friendly description of the event.
     */
    public function name(string $description): static
    {
        return $this->description($description);
    }

    /**
     * Set the human-friendly description of the event.
     */
    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }
}
