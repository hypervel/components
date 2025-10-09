<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Exception;
use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Horizon\Events\MasterSupervisorDeployed;
use Hypervel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;

class ProvisioningPlan
{
    /**
     * The raw provisioning plan.
     */
    public array $plan;

    /**
     * The parsed provisioning plan.
     */
    public array $parsed;

    /**
     * Create a new provisioning plan instance.
     *
     * @param string $master the master supervisor's name
     */
    public function __construct(
        public string $master,
        array $plan,
        array $defaults = []
    ) {
        $this->plan = $this->applyDefaultOptions($plan, $defaults);
        $this->parsed = $this->toSupervisorOptions();
    }

    /**
     * Get the current provisioning plan.
     */
    public static function get(string $master): static
    {
        return new static($master, config('horizon.environments'), config('horizon.defaults', []));
    }

    /**
     * Apply the default supervisor options to each environment.
     */
    protected function applyDefaultOptions(array $plan, array $defaults = []): array
    {
        return collect($plan)->map(function ($plan) use ($defaults) {
            return array_replace_recursive($defaults, $plan);
        })->all();
    }

    /**
     * Get all of the defined environments for the provisioning plan.
     */
    public function environments(): array
    {
        return array_keys($this->parsed);
    }

    /**
     * Determine if the provisioning plan has a given environment.
     */
    public function hasEnvironment(string $environment): bool
    {
        return array_key_exists($environment, $this->parsed);
    }

    /**
     * Deploy a provisioning plan to the current machine.
     */
    public function deploy(string $environment): void
    {
        $supervisors = collect($this->parsed)->first(function ($_, $name) use ($environment) {
            return Str::is($name, $environment);
        });

        if (empty($supervisors)) {
            return;
        }

        foreach ($supervisors as $supervisor => $options) {
            if ($options->maxProcesses > 0) {
                $this->add($options);
            }
        }

        event(new MasterSupervisorDeployed($this->master));
    }

    /**
     * Add a supervisor with the given options.
     */
    protected function add(SupervisorOptions $options): void
    {
        app(HorizonCommandQueue::class)->push(
            MasterSupervisor::commandQueueFor($this->master),
            AddSupervisor::class,
            $options->toArray()
        );
    }

    /**
     * Get the SupervisorOptions for a given environment and supervisor.
     */
    public function optionsFor(string $environment, string $supervisor): ?SupervisorOptions
    {
        return $this->parsed[$environment][$supervisor] ?? null;
    }

    /**
     * Convert the provisioning plan into an array of SupervisorOptions.
     */
    public function toSupervisorOptions(): array
    {
        return collect($this->plan)->mapWithKeys(function ($plan, $environment) {
            return [$environment => collect($plan)->mapWithKeys(function ($options, $supervisor) {
                return [$supervisor => $this->convert($supervisor, $options)];
            })];
        })->all();
    }

    /**
     * Convert the given array of options into a SupervisorOptions instance.
     */
    protected function convert(string $supervisor, array $options): SupervisorOptions
    {
        $options = collect($options)->mapWithKeys(function ($value, $key) {
            $key = $key === 'tries' ? 'max_tries' : $key;
            $key = $key === 'processes' ? 'max_processes' : $key;
            $value = $key === 'queue' && is_array($value) ? implode(',', $value) : $value;
            $value = $key === 'backoff' && is_array($value) ? implode(',', $value) : $value;

            return [Str::camel($key) => $value];
        })->all();

        if (isset($options['minProcesses']) && $options['minProcesses'] < 1) {
            throw new Exception("The value of [{$supervisor}.minProcesses] must be greater than 0.");
        }

        $options['parentId'] = getmypid();

        return SupervisorOptions::fromArray(
            Arr::add($options, 'name', $this->master . ":{$supervisor}")
        );
    }
}
