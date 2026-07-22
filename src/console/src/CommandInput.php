<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Dumpable;
use Hypervel\Support\Traits\InteractsWithData;

class CommandInput
{
    use Dumpable;
    use InteractsWithData;

    /**
     * The command arguments.
     *
     * @var array<string, mixed>
     */
    protected array $arguments;

    /**
     * The command options.
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * Create a new command input container.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     */
    public function __construct(array $arguments = [], array $options = [])
    {
        $this->arguments = $arguments;
        $this->options = $options;
    }

    /**
     * Get all of the input for the command.
     *
     * Arguments take precedence over options when keys collide.
     *
     * @return array<string, mixed>
     */
    public function all(mixed $keys = null): array
    {
        $input = array_merge($this->options, $this->arguments);

        if (! $keys) {
            return $input;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    /**
     * Retrieve data from the instance.
     */
    protected function data(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
    }

    /**
     * Get all of the arguments passed to the command.
     *
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * Get all of the options passed to the command.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * Dynamically access input data.
     */
    public function __get(string $name): mixed
    {
        return $this->data($name);
    }

    /**
     * Determine if an input item is set.
     */
    public function __isset(string $name): bool
    {
        return $this->exists($name);
    }
}
