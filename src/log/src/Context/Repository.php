<?php

declare(strict_types=1);

namespace Hypervel\Log\Context;

use __PHP_Incomplete_Class;
use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\ReplicableContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Log\Context\Events\ContextDehydrating;
use Hypervel\Log\Context\Events\ContextHydrated;
use Hypervel\Queue\SerializesAndRestoresModelIdentifiers;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class Repository implements ReplicableContext
{
    use Conditionable;
    use Macroable;
    use SerializesAndRestoresModelIdentifiers;

    public const CONTEXT_KEY = '__log.context_repository';

    /**
     * The contextual data that flows to logs and jobs.
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * The hidden contextual data that flows to jobs but not logs.
     *
     * @var array<string, mixed>
     */
    protected array $hidden = [];

    /**
     * The callback that should handle unserialize exceptions.
     *
     * @var null|(Closure(Throwable, string, string, bool): mixed)
     */
    protected static ?Closure $handleUnserializeExceptionsUsing = null;

    /**
     * Create a new context repository instance.
     */
    public function __construct(
        protected Dispatcher $events
    ) {
    }

    /**
     * Get the context repository instance for the current coroutine.
     *
     * Creates the instance on first access. For hot paths that should avoid
     * unnecessary allocation (log processors, queue hooks), use hasInstance()
     * to check first.
     */
    public static function getInstance(): static
    {
        return CoroutineContext::getOrSet(
            static::CONTEXT_KEY,
            fn () => new static(app(Dispatcher::class))
        );
    }

    /**
     * Determine if a context repository instance exists for the current coroutine.
     *
     * Unlike getInstance(), this does NOT create one if it doesn't exist.
     */
    public static function hasInstance(): bool
    {
        return CoroutineContext::has(static::CONTEXT_KEY);
    }

    // --- Data (flows to logs AND jobs) ---

    /**
     * Determine if the given key exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Determine if the given key is missing.
     */
    public function missing(string $key): bool
    {
        return ! $this->has($key);
    }

    /**
     * Retrieve all the context data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Retrieve the given key's value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? value($default);
    }

    /**
     * Retrieve the given key's value and then forget it.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        return tap($this->get($key, $default), function () use ($key) {
            $this->forget($key);
        });
    }

    /**
     * Retrieve only the values of the given keys.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    /**
     * Retrieve all values except those with the given keys.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    /**
     * Add a context value.
     *
     * @param array<string, mixed>|string $key
     * @return $this
     */
    public function add(string|array $key, mixed $value = null): static
    {
        $this->data = array_merge(
            $this->data,
            is_array($key) ? $key : [$key => $value]
        );

        return $this;
    }

    /**
     * Add a context value if it does not exist yet.
     *
     * @return $this
     */
    public function addIf(string $key, mixed $value): static
    {
        if (! $this->has($key)) {
            $this->add($key, $value);
        }

        return $this;
    }

    /**
     * Add a context value if it does not exist yet, and return the value.
     */
    public function remember(string $key, mixed $value): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        return tap(value($value), function ($value) use ($key) {
            $this->add($key, $value);
        });
    }

    /**
     * Forget the given context key.
     *
     * @param array<int, string>|string $key
     * @return $this
     */
    public function forget(string|array $key): static
    {
        foreach ((array) $key as $k) {
            unset($this->data[$k]);
        }

        return $this;
    }

    /**
     * Push values onto a context stack.
     *
     * @return $this
     * @throws RuntimeException
     */
    public function push(string $key, mixed ...$values): static
    {
        if (! $this->isStackable($key)) {
            throw new RuntimeException("Unable to push value onto context stack for key [{$key}].");
        }

        $this->data[$key] = [
            ...$this->data[$key] ?? [],
            ...$values,
        ];

        return $this;
    }

    /**
     * Pop the latest value from a context stack.
     *
     * @throws RuntimeException
     */
    public function pop(string $key): mixed
    {
        if (! $this->isStackable($key) || ! count($this->data[$key])) {
            throw new RuntimeException("Unable to pop value from context stack for key [{$key}].");
        }

        return array_pop($this->data[$key]);
    }

    /**
     * Determine if the given value is in the given stack.
     *
     * @throws RuntimeException
     */
    public function stackContains(string $key, mixed $value, bool $strict = false): bool
    {
        if (! $this->isStackable($key)) {
            throw new RuntimeException("Given key [{$key}] is not a stack.");
        }

        if (! array_key_exists($key, $this->data)) {
            return false;
        }

        if ($value instanceof Closure) {
            return (new Collection($this->data[$key]))->contains($value);
        }

        return in_array($value, $this->data[$key], $strict);
    }

    /**
     * Increment a context counter.
     *
     * @return $this
     */
    public function increment(string $key, int $amount = 1): static
    {
        $this->add(
            $key,
            (int) $this->get($key, 0) + $amount,
        );

        return $this;
    }

    /**
     * Decrement a context counter.
     *
     * @return $this
     */
    public function decrement(string $key, int $amount = 1): static
    {
        return $this->increment($key, $amount * -1);
    }

    // --- Hidden data (flows to jobs only, NOT logs) ---

    /**
     * Determine if the given key exists within the hidden context data.
     */
    public function hasHidden(string $key): bool
    {
        return array_key_exists($key, $this->hidden);
    }

    /**
     * Determine if the given key is missing within the hidden context data.
     */
    public function missingHidden(string $key): bool
    {
        return ! $this->hasHidden($key);
    }

    /**
     * Retrieve all the hidden context data.
     *
     * @return array<string, mixed>
     */
    public function allHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Retrieve the given key's hidden value.
     */
    public function getHidden(string $key, mixed $default = null): mixed
    {
        return $this->hidden[$key] ?? value($default);
    }

    /**
     * Retrieve the given key's hidden value and then forget it.
     */
    public function pullHidden(string $key, mixed $default = null): mixed
    {
        return tap($this->getHidden($key, $default), function () use ($key) {
            $this->forgetHidden($key);
        });
    }

    /**
     * Retrieve only the hidden values of the given keys.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function onlyHidden(array $keys): array
    {
        return array_intersect_key($this->hidden, array_flip($keys));
    }

    /**
     * Retrieve all hidden values except those with the given keys.
     *
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function exceptHidden(array $keys): array
    {
        return array_diff_key($this->hidden, array_flip($keys));
    }

    /**
     * Add a hidden context value.
     *
     * @param array<string, mixed>|string $key
     * @return $this
     */
    public function addHidden(string|array $key, #[SensitiveParameter] mixed $value = null): static
    {
        $this->hidden = array_merge(
            $this->hidden,
            is_array($key) ? $key : [$key => $value]
        );

        return $this;
    }

    /**
     * Add a hidden context value if it does not exist yet.
     *
     * @return $this
     */
    public function addHiddenIf(string $key, #[SensitiveParameter] mixed $value): static
    {
        if (! $this->hasHidden($key)) {
            $this->addHidden($key, $value);
        }

        return $this;
    }

    /**
     * Add a hidden context value if it does not exist yet, and return the value.
     */
    public function rememberHidden(string $key, #[SensitiveParameter] mixed $value): mixed
    {
        if ($this->hasHidden($key)) {
            return $this->getHidden($key);
        }

        return tap(value($value), function ($value) use ($key) {
            $this->addHidden($key, $value);
        });
    }

    /**
     * Forget the given hidden context key.
     *
     * @param array<int, string>|string $key
     * @return $this
     */
    public function forgetHidden(string|array $key): static
    {
        foreach ((array) $key as $k) {
            unset($this->hidden[$k]);
        }

        return $this;
    }

    /**
     * Push values onto a hidden context stack.
     *
     * @return $this
     * @throws RuntimeException
     */
    public function pushHidden(string $key, mixed ...$values): static
    {
        if (! $this->isHiddenStackable($key)) {
            throw new RuntimeException("Unable to push value onto hidden context stack for key [{$key}].");
        }

        $this->hidden[$key] = [
            ...$this->hidden[$key] ?? [],
            ...$values,
        ];

        return $this;
    }

    /**
     * Pop the latest value from a hidden context stack.
     *
     * @throws RuntimeException
     */
    public function popHidden(string $key): mixed
    {
        if (! $this->isHiddenStackable($key) || ! count($this->hidden[$key])) {
            throw new RuntimeException("Unable to pop value from hidden context stack for key [{$key}].");
        }

        return array_pop($this->hidden[$key]);
    }

    /**
     * Determine if the given value is in the given hidden stack.
     *
     * @throws RuntimeException
     */
    public function hiddenStackContains(string $key, mixed $value, bool $strict = false): bool
    {
        if (! $this->isHiddenStackable($key)) {
            throw new RuntimeException("Given key [{$key}] is not a stack.");
        }

        if (! array_key_exists($key, $this->hidden)) {
            return false;
        }

        if ($value instanceof Closure) {
            return (new Collection($this->hidden[$key]))->contains($value);
        }

        return in_array($value, $this->hidden[$key], $strict);
    }

    // --- Scope ---

    /**
     * Run a callback with temporary context, then restore.
     *
     * @template TReturn of mixed
     * @param (callable(): TReturn) $callback
     * @param array<string, mixed> $data
     * @param array<string, mixed> $hidden
     * @return TReturn
     * @throws Throwable
     */
    public function scope(callable $callback, array $data = [], array $hidden = []): mixed
    {
        $dataBefore = $this->data;
        $hiddenBefore = $this->hidden;

        if ($data !== []) {
            $this->add($data);
        }

        if ($hidden !== []) {
            $this->addHidden($hidden);
        }

        try {
            return $callback();
        } finally {
            $this->data = $dataBefore;
            $this->hidden = $hiddenBefore;
        }
    }

    // --- Lifecycle ---

    /**
     * Determine if the context is empty.
     */
    public function isEmpty(): bool
    {
        return $this->all() === [] && $this->allHidden() === [];
    }

    /**
     * Flush all context data.
     *
     * @return $this
     */
    public function flush(): static
    {
        $this->data = [];
        $this->hidden = [];

        return $this;
    }

    /**
     * Create an independent copy with the same data and hidden values.
     *
     * Used by CoroutineContext::copyFrom() via ReplicableContext to ensure
     * forked coroutines get their own instance rather than sharing an
     * object reference with the parent.
     */
    public function replicate(): static
    {
        return (new static($this->events))
            ->add($this->all())
            ->addHidden($this->allHidden());
    }

    // --- Transport hooks ---

    /**
     * Register a callback to execute before context is dehydrated for a job.
     *
     * @param (callable(self): void) $callback
     * @return $this
     */
    public function dehydrating(callable $callback): static
    {
        $this->events->listen(fn (ContextDehydrating $event) => $callback($event->context));

        return $this;
    }

    /**
     * Register a callback to execute after context has been hydrated from a job.
     *
     * @param (callable(self): void) $callback
     * @return $this
     */
    public function hydrated(callable $callback): static
    {
        $this->events->listen(fn (ContextHydrated $event) => $callback($event->context));

        return $this;
    }

    /**
     * Set the callback to handle unserialize exceptions.
     *
     * @return $this
     */
    public function handleUnserializeExceptionsUsing(?callable $callback): static
    {
        static::$handleUnserializeExceptionsUsing = $callback !== null
            ? $callback(...)
            : null;

        return $this;
    }

    /**
     * Reset all static state on the class.
     */
    public static function flushState(): void
    {
        static::$handleUnserializeExceptionsUsing = null;
        static::flushMacros();
    }

    // --- Internal transport (called by queue infrastructure) ---

    /**
     * Dehydrate the context into a serializable payload.
     *
     * Creates a clone of this instance, dispatches ContextDehydrating so listeners
     * can modify the clone (not the original), then serializes all values.
     * Uses SerializesAndRestoresModelIdentifiers to handle Eloquent models.
     *
     * Returns null if both data and hidden are empty after dehydration hooks run.
     *
     * @internal
     * @return null|array{data: array<string, string>, hidden: array<string, string>}
     */
    public function dehydrate(): ?array
    {
        $instance = (new static($this->events))
            ->add($this->all())
            ->addHidden($this->allHidden());

        $instance->events->dispatch(new ContextDehydrating($instance));

        $serialize = fn (mixed $value): string => serialize(
            $instance->getSerializedPropertyValue($value, withRelations: false)
        );

        return $instance->isEmpty() ? null : [
            'data' => array_map($serialize, $instance->all()),
            'hidden' => array_map($serialize, $instance->allHidden()),
        ];
    }

    /**
     * Hydrate the context from a serialized payload.
     *
     * Flushes existing data, deserializes the payload, then dispatches
     * ContextHydrated so listeners can react to the restored data.
     * Uses SerializesAndRestoresModelIdentifiers to restore Eloquent models.
     *
     * @internal
     * @param null|array{data?: array<string, string>, hidden?: array<string, string>} $context
     * @return $this
     * @throws RuntimeException
     */
    public function hydrate(?array $context): static
    {
        $unserialize = function (string $value, string $key, bool $hidden): mixed {
            try {
                return tap($this->getRestoredPropertyValue(unserialize($value)), function ($value) {
                    if ($value instanceof __PHP_Incomplete_Class) {
                        throw new RuntimeException('Value is incomplete class: ' . json_encode($value));
                    }
                });
            } catch (Throwable $exception) {
                if (static::$handleUnserializeExceptionsUsing !== null) {
                    return (static::$handleUnserializeExceptionsUsing)($exception, $key, $value, $hidden);
                }

                if ($exception instanceof ModelNotFoundException) {
                    if (function_exists('report')) {
                        report($exception);
                    }

                    return null;
                }

                throw $exception;
            }
        };

        [$data, $hidden] = [
            (new Collection($context['data'] ?? []))
                ->map(fn (string $value, string $key) => $unserialize($value, $key, false))
                ->all(),
            (new Collection($context['hidden'] ?? []))
                ->map(fn (string $value, string $key) => $unserialize($value, $key, true))
                ->all(),
        ];

        $this->events->dispatch(new ContextHydrated(
            $this->flush()->add($data)->addHidden($hidden)
        ));

        return $this;
    }

    // --- Internal helpers ---

    /**
     * Determine if a given key can be used as a stack.
     */
    protected function isStackable(string $key): bool
    {
        return ! $this->has($key)
            || (is_array($this->data[$key]) && array_is_list($this->data[$key]));
    }

    /**
     * Determine if a given key can be used as a hidden stack.
     */
    protected function isHiddenStackable(string $key): bool
    {
        return ! $this->hasHidden($key)
            || (is_array($this->hidden[$key]) && array_is_list($this->hidden[$key]));
    }
}
