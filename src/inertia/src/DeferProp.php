<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

class DeferProp implements Deferrable, IgnoreFirstLoad, Mergeable, Onceable, Rescuable
{
    use DefersProps;
    use MergesProps;
    use ResolvesCallables;
    use ResolvesOnce;

    /**
     * The callback to resolve the property.
     *
     * Loaded asynchronously after initial page render for performance.
     *
     * @var callable
     */
    protected $callback;

    /**
     * Indicates if exceptions should be rescued during deferred resolution.
     */
    protected bool $rescue;

    /**
     * Create a new deferred property instance. Deferred properties are excluded
     * from the initial page load and only evaluated when requested by the
     * frontend, improving initial page performance.
     */
    public function __construct(callable $callback, ?string $group = null, bool $rescue = false)
    {
        $this->callback = $callback;
        $this->rescue = $rescue;
        $this->defer($group);
    }

    /**
     * Resolve the property value.
     */
    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->callback);
    }

    /**
     * Determine if deferred resolution errors should be rescued.
     */
    public function shouldRescue(): bool
    {
        return $this->rescue;
    }
}
