<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use ArrayObject;
use Hypervel\Contracts\Engine\CoroutineInterface;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Engine\Exceptions\CoroutineDestroyedException;
use Hypervel\Engine\Exceptions\RunningInNonCoroutineException;
use Hypervel\Engine\Exceptions\RuntimeException;
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
    public static function create(callable $callable, mixed ...$data): static
    {
        $coroutine = new static($callable);
        $coroutine->execute(...$data);
        return $coroutine;
    }

    /**
     * Execute the coroutine.
     */
    public function execute(mixed ...$data): static
    {
        // Swoole warns when its coroutine limit is exceeded; expose that native
        // failure through the typed framework exception instead of two signals.
        $id = @SwooleCo::create($this->callable, ...$data);

        if ($id === false) {
            throw CoroutineCreateException::fromLastError();
        }

        $this->id = $id;

        return $this;
    }

    /**
     * Get the coroutine ID.
     */
    public function getId(): int
    {
        if (is_null($this->id)) {
            throw new RuntimeException('Coroutine has not been executed.');
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
            throw new RunningInNonCoroutineException('Cannot retrieve a parent coroutine ID outside a coroutine.');
        }
        return max(0, $cid);
    }

    /**
     * Set the coroutine configuration.
     *
     * Boot-only. Mutates Swoole's process-global coroutine configuration; calling
     * this per-request changes settings for every concurrent coroutine in the worker.
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
     */
    public static function yield(): bool
    {
        return SwooleCo::yield();
    }

    /**
     * Resume a coroutine by ID.
     */
    public static function resumeById(int $id): bool
    {
        return SwooleCo::resume($id);
    }

    /**
     * Cancel a coroutine by ID.
     */
    public static function cancelById(int $id, bool $throwException = false): bool
    {
        /* @phpstan-ignore arguments.count (@TODO: Remove once PHPStan's bundled JetBrains Swoole stub includes Swoole 6.2's throw_exception parameter.) */
        return SwooleCo::cancel($id, $throwException);
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
    public static function exists(int $id): bool
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
