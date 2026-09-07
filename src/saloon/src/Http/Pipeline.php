<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Saloon\Data\Pipe;
use Hypervel\Saloon\Enums\PipeOrder;
use Hypervel\Saloon\Exceptions\DuplicatePipeNameException;

/** @template TPayload */
class Pipeline
{
    /**
     * The first pipes in the pipeline.
     *
     * @var list<Pipe>
     */
    protected array $firstPipes = [];

    /**
     * The default pipes in the pipeline.
     *
     * @var list<Pipe>
     */
    protected array $pipes = [];

    /**
     * The last pipes in the pipeline.
     *
     * @var list<Pipe>
     */
    protected array $lastPipes = [];

    /**
     * The registered pipe names.
     *
     * @var array<string, true>
     */
    protected array $names = [];

    /**
     * Add a pipe to the pipeline.
     *
     * @param callable(TPayload): TPayload $callable
     * @return $this
     */
    public function pipe(callable $callable, ?string $name = null, ?PipeOrder $order = null): static
    {
        if ($name !== null && isset($this->names[$name])) {
            throw new DuplicatePipeNameException($name);
        }

        $pipe = new Pipe($callable, $name, $order);

        match ($order) {
            PipeOrder::First => $this->firstPipes[] = $pipe,
            null => $this->pipes[] = $pipe,
            PipeOrder::Last => $this->lastPipes[] = $pipe,
        };

        if ($name !== null) {
            $this->names[$name] = true;
        }

        return $this;
    }

    /**
     * Process the pipeline.
     */
    public function process(mixed $payload): mixed
    {
        foreach ([$this->firstPipes, $this->pipes, $this->lastPipes] as $pipes) {
            foreach ($pipes as $pipe) {
                $payload = ($pipe->callable)($payload);
            }
        }

        return $payload;
    }

    /**
     * Set the pipes on the pipeline.
     *
     * @param list<Pipe> $pipes
     * @return $this
     */
    public function setPipes(array $pipes): static
    {
        $this->firstPipes = [];
        $this->pipes = [];
        $this->lastPipes = [];
        $this->names = [];

        foreach ($pipes as $pipe) {
            $this->pipe($pipe->callable, $pipe->name, $pipe->order);
        }

        return $this;
    }

    /**
     * Get all the pipes in execution order.
     *
     * @return list<Pipe>
     */
    public function pipes(): array
    {
        return [...$this->firstPipes, ...$this->pipes, ...$this->lastPipes];
    }
}
