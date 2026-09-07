<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Deferred\Metrics;

use Closure;
use Hypervel\OpenTelemetry\Deferred\InstrumentationScope;
use LogicException;
use OpenTelemetry\API\Metrics\AsynchronousInstrument;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use Override;
use ReflectionFunction;
use ReflectionMethod;
use stdClass;
use WeakMap;
use WeakReference;

/**
 * Keep pre-fork metric instruments and callbacks usable after worker binding.
 *
 * @internal
 */
class DeferredMeter implements MeterInterface
{
    protected ?MeterInterface $delegate = null;

    /** @var list<DeferredCounter|DeferredGauge|DeferredHistogram|DeferredUpDownCounter> */
    protected array $synchronousInstruments = [];

    /** @var list<DeferredObservableCounter|DeferredObservableGauge|DeferredObservableUpDownCounter> */
    protected array $observableInstruments = [];

    /** @var array<int, DeferredObservableCallback> */
    protected array $observableCallbacks = [];

    /** @var WeakMap<object, DeferredCallbackDestructor> */
    protected WeakMap $callbackDestructors;

    /**
     * Create a deferred meter.
     */
    public function __construct(protected readonly InstrumentationScope $instrumentationScope)
    {
        $this->callbackDestructors = new WeakMap;
    }

    /**
     * Bind this handle and every pre-fork instrument to the given provider.
     */
    public function bind(MeterProviderInterface $provider): void
    {
        $this->unbind();
        $this->delegate = $provider->getMeter(
            $this->instrumentationScope->name,
            $this->instrumentationScope->version,
            $this->instrumentationScope->schemaUrl,
            $this->instrumentationScope->attributes,
        );

        foreach ($this->synchronousInstruments as $instrument) {
            $instrument->bind($this->delegate);
        }

        foreach ($this->observableInstruments as $instrument) {
            $instrument->bind($this->delegate);
        }

        foreach ($this->observableCallbacks as $callback) {
            $callback->bind($this->delegate);
        }
    }

    /**
     * Unbind this handle, its instruments, and its callback registrations.
     */
    public function unbind(): void
    {
        foreach ($this->observableCallbacks as $callback) {
            $callback->unbind();
        }

        foreach ($this->observableInstruments as $instrument) {
            $instrument->unbind();
        }

        foreach ($this->synchronousInstruments as $instrument) {
            $instrument->unbind();
        }

        $this->delegate = null;
    }

    /**
     * Register one callback for multiple asynchronous instruments.
     */
    #[Override]
    public function batchObserve(
        callable $callback,
        AsynchronousInstrument $instrument,
        AsynchronousInstrument ...$instruments,
    ): ObservableCallbackInterface {
        array_unshift($instruments, $instrument);

        if ($this->delegate !== null && ! $this->containsOwnedDeferredInstrument($instruments)) {
            $instrument = array_shift($instruments);

            return $this->delegate->batchObserve($callback, $instrument, ...$instruments);
        }

        return $this->registerObservableCallback($callback, $instruments, true);
    }

    /**
     * Create a counter.
     */
    #[Override]
    public function createCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): CounterInterface {
        if ($this->delegate !== null) {
            return $this->delegate->createCounter($name, $unit, $description, $advisory);
        }

        return $this->synchronousInstruments[] = new DeferredCounter($name, $unit, $description, $advisory);
    }

    /**
     * Create an observable counter.
     */
    #[Override]
    public function createObservableCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableCounterInterface {
        if ($this->delegate !== null) {
            return $this->delegate->createObservableCounter($name, $unit, $description, $advisory, ...$callbacks);
        }

        [$advisory, $callbacks] = $this->normalizeObservableArguments($advisory, $callbacks);

        return $this->observableInstruments[] = new DeferredObservableCounter(
            $this,
            $name,
            $unit,
            $description,
            $advisory,
            $callbacks,
        );
    }

    /**
     * Create a histogram.
     */
    #[Override]
    public function createHistogram(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): HistogramInterface {
        if ($this->delegate !== null) {
            return $this->delegate->createHistogram($name, $unit, $description, $advisory);
        }

        return $this->synchronousInstruments[] = new DeferredHistogram($name, $unit, $description, $advisory);
    }

    /**
     * Create a gauge.
     */
    #[Override]
    public function createGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): GaugeInterface {
        if ($this->delegate !== null) {
            return $this->delegate->createGauge($name, $unit, $description, $advisory);
        }

        return $this->synchronousInstruments[] = new DeferredGauge($name, $unit, $description, $advisory);
    }

    /**
     * Create an observable gauge.
     */
    #[Override]
    public function createObservableGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableGaugeInterface {
        if ($this->delegate !== null) {
            return $this->delegate->createObservableGauge($name, $unit, $description, $advisory, ...$callbacks);
        }

        [$advisory, $callbacks] = $this->normalizeObservableArguments($advisory, $callbacks);

        return $this->observableInstruments[] = new DeferredObservableGauge(
            $this,
            $name,
            $unit,
            $description,
            $advisory,
            $callbacks,
        );
    }

    /**
     * Create an up-down counter.
     */
    #[Override]
    public function createUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = [],
    ): UpDownCounterInterface {
        if ($this->delegate !== null) {
            return $this->delegate->createUpDownCounter($name, $unit, $description, $advisory);
        }

        return $this->synchronousInstruments[] = new DeferredUpDownCounter($name, $unit, $description, $advisory);
    }

    /**
     * Create an observable up-down counter.
     */
    #[Override]
    public function createObservableUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array|callable $advisory = [],
        callable ...$callbacks,
    ): ObservableUpDownCounterInterface {
        if ($this->delegate !== null) {
            return $this->delegate->createObservableUpDownCounter($name, $unit, $description, $advisory, ...$callbacks);
        }

        [$advisory, $callbacks] = $this->normalizeObservableArguments($advisory, $callbacks);

        return $this->observableInstruments[] = new DeferredObservableUpDownCounter(
            $this,
            $name,
            $unit,
            $description,
            $advisory,
            $callbacks,
        );
    }

    /**
     * Register an observable callback that follows worker rebinding.
     *
     * @param non-empty-list<AsynchronousInstrument> $instruments
     * @internal
     */
    public function registerObservableCallback(
        callable $callback,
        array $instruments,
        bool $batch = false,
    ): ObservableCallbackInterface {
        $target = null;
        $callback = self::weakenCallback($callback, $target);
        $registration = new DeferredObservableCallback(
            $this,
            $callback,
            $instruments,
            $batch,
            $target === null ? null : WeakReference::create($target),
        );
        $this->observableCallbacks[spl_object_id($registration)] = $registration;

        if ($target !== null) {
            $destructor = $this->callbackDestructors[$target] ??= new DeferredCallbackDestructor;
            $destructor->add($registration);
        }

        if ($this->delegate !== null) {
            $registration->bind($this->delegate);
        }

        return $registration;
    }

    /**
     * Forget a detached observable callback.
     *
     * @internal
     */
    public function forgetObservableCallback(
        DeferredObservableCallback $callback,
        ?WeakReference $target,
    ): void {
        unset($this->observableCallbacks[spl_object_id($callback)]);

        $target = $target?->get();

        if ($target === null || ! isset($this->callbackDestructors[$target])) {
            return;
        }

        $destructor = $this->callbackDestructors[$target];
        $destructor->forget($callback);

        if ($destructor->isEmpty()) {
            unset($this->callbackDestructors[$target]);
        }
    }

    /**
     * Map this meter's deferred instruments to their current delegates.
     *
     * @param non-empty-list<AsynchronousInstrument> $instruments
     * @return non-empty-list<AsynchronousInstrument>
     * @internal
     */
    public function mapObservableInstruments(array $instruments): array
    {
        foreach ($instruments as $index => $instrument) {
            if (($instrument instanceof DeferredObservableCounter
                    || $instrument instanceof DeferredObservableGauge
                    || $instrument instanceof DeferredObservableUpDownCounter)
                && $instrument->belongsTo($this)) {
                $instruments[$index] = $instrument->delegate()
                    ?? throw new LogicException('A deferred observable instrument was not bound before its callback.');
            }
        }

        return $instruments;
    }

    /**
     * Determine whether the list contains an instrument owned by this meter.
     *
     * @param non-empty-list<AsynchronousInstrument> $instruments
     */
    protected function containsOwnedDeferredInstrument(array $instruments): bool
    {
        foreach ($instruments as $instrument) {
            if (($instrument instanceof DeferredObservableCounter
                    || $instrument instanceof DeferredObservableGauge
                    || $instrument instanceof DeferredObservableUpDownCounter)
                && $instrument->belongsTo($this)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize the observable advisory and creation callbacks.
     *
     * @param list<callable> $callbacks
     * @return array{array, list<callable>}
     */
    protected function normalizeObservableArguments(array|callable $advisory, array $callbacks): array
    {
        if (is_callable($advisory)) {
            array_unshift($callbacks, $advisory);
            $advisory = [];
        }

        return [$advisory, array_values($callbacks)];
    }

    /**
     * Convert a callback to weak target ownership when it is object-bound.
     *
     * This reproduces the SDK's weak-target lifetime with public PHP APIs, so
     * discarding the registration token cannot retain the callback's object.
     */
    protected static function weakenCallback(callable $callback, ?object &$target): Closure
    {
        $callback = Closure::fromCallable($callback);
        $reflection = new ReflectionFunction($callback);

        if (($target = $reflection->getClosureThis()) === null) {
            return $callback;
        }

        $scope = $reflection->getClosureScopeClass();
        $name = $reflection->getShortName();
        $reference = WeakReference::create($target);

        if (! str_starts_with($name, '{closure')) {
            $method = new ReflectionMethod($scope->name, $name);

            return static fn (...$arguments) => ($object = $reference->get())
                ? $method->invoke($object, ...$arguments)
                : null;
        }

        // One stateless placeholder removes the original strong binding without per-callback allocation.
        static $placeholder;
        $placeholder ??= new stdClass;
        $callback = $callback->bindTo($placeholder);

        return $scope !== null && $target::class === $scope->name && ! $scope->isInternal()
            ? static fn (...$arguments) => ($object = $reference->get())
                ? $callback->call($object, ...$arguments)
                : null
            : static fn (...$arguments) => ($object = $reference->get())
                ? $callback->bindTo($object)(...$arguments)
                : null;
    }
}
