<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use ArrayObject;
use Hypervel\Contracts\Engine\CoroutineInterface;
use Hypervel\Engine\Exception\CoroutineDestroyedException;
use Hypervel\Engine\Exception\RunningInNonCoroutineException;
use Hypervel\Engine\Exception\RuntimeException;
use Swoole\Coroutine as SwooleCo;

class Coroutine implements CoroutineInterface
{
    /**
     * @var callable
     */
    private $callable;

    private ?int $id = null;

    /**
     * Create a new coroutine instance.
     */
    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    /**
     * Create and execute a new coroutine.
     */
    public static function create(callable $callable, ...$data): static
    {
        $coroutine = new static($callable);
        $coroutine->execute(...$data);
        return $coroutine;
    }

    /**
     * Execute the coroutine.
     */
    public function execute(...$data): static
    {
        $this->id = SwooleCo::create($this->callable, ...$data);
        return $this;
    }

    /**
     * Get the coroutine ID.
     */
    public function getId(): int
    {
        if (is_null($this->id)) {
            throw new RuntimeException('Coroutine was not be executed.');
        }
        return $this->id;
    }

    /**
     * Get the current coroutine ID.
     */
    public static function id(): int
    {
        return SwooleCo::getCid();
    }

    /**
     * Get the parent coroutine ID.
     */
    public static function pid(?int $id = null): int
    {
        if ($id) {
            $cid = SwooleCo::getPcid($id);
            if ($cid === false) {
                throw new CoroutineDestroyedException(sprintf('Coroutine #%d has been destroyed.', $id));
            }
        } else {
            $cid = SwooleCo::getPcid();
        }
        if ($cid === false) {
            throw new RunningInNonCoroutineException('Non-Coroutine environment don\'t has parent coroutine id.');
        }
        return max(0, $cid);
    }

    /**
     * Set the coroutine configuration.
     */
    public static function set(array $config): void
    {
        SwooleCo::set($config);
    }

    /**
     * Get the coroutine context.
     */
    public static function getContextFor(?int $id = null): ?ArrayObject
    {
        if ($id === null) {
            return SwooleCo::getContext();
        }

        return SwooleCo::getContext($id);
    }

    /**
     * Register a callback to be executed when the coroutine ends.
     */
    public static function defer(callable $callable): void
    {
        SwooleCo::defer($callable);
    }

    /**
     * Yield the current coroutine.
     *
     * @param mixed $data only supported in Swow
     * @return bool
     */
    public static function yield(mixed $data = null): mixed
    {
        return SwooleCo::yield();
    }

    /**
     * Resume a coroutine by ID.
     *
     * @param mixed $data only supported in Swow
     * @return bool
     */
    public static function resumeById(int $id, mixed ...$data): mixed
    {
        return SwooleCo::resume($id);
    }

    /**
     * Get the coroutine statistics.
     */
    public static function stats(): array
    {
        return SwooleCo::stats();
    }

    /**
     * Determine if a coroutine exists.
     */
    public static function exists(?int $id = null): bool
    {
        return SwooleCo::exists($id);
    }

    /**
     * Get all coroutine IDs.
     *
     * @return iterable<int>
     */
    public static function list(): iterable
    {
        foreach (SwooleCo::list() as $cid) {
            yield $cid;
        }
    }
}
