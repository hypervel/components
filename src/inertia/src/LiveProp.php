<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Broadcasting\Channel;

class LiveProp implements SupportsLiveUpdates
{
    use HasLiveUpdates;
    use ResolvesCallables;

    /**
     * The property value.
     */
    protected mixed $value;

    /**
     * Create a new live property instance. Live properties are refreshed with
     * a partial reload whenever one of the given events is received.
     *
     * @param null|array<int, class-string|object|string>|class-string|object|string $on
     * @param null|array<int, Channel|string>|Channel|string $channel
     */
    public function __construct(mixed $value, array|object|string|null $on = null, array|Channel|string|null $channel = null, ?int $throttle = null)
    {
        $this->value = $value;
        $this->live($on, $channel, $throttle);
    }

    /**
     * Resolve the property value.
     */
    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->value);
    }
}
