<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hyperf\Context\ApplicationContext;
use Hypervel\Contracts\Cache\Factory as Cache;
use Hypervel\Contracts\Queue\ShouldBeUnique;

class UniqueBroadcastEvent extends BroadcastEvent implements ShouldBeUnique
{
    /**
     * The unique lock identifier.
     */
    public string $uniqueId;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor;

    /**
     * Create a new event instance.
     */
    public function __construct(mixed $event)
    {
        $this->uniqueId = get_class($event);

        if (method_exists($event, 'uniqueId')) {
            $this->uniqueId .= $event->uniqueId();
        } elseif (property_exists($event, 'uniqueId')) {
            $this->uniqueId .= $event->uniqueId;
        }

        if (method_exists($event, 'uniqueFor')) {
            $this->uniqueFor = $event->uniqueFor();
        } elseif (property_exists($event, 'uniqueFor')) {
            $this->uniqueFor = $event->uniqueFor;
        }

        parent::__construct($event);
    }

    /**
     * Resolve the cache implementation that should manage the event's uniqueness.
     */
    public function uniqueVia(): Cache
    {
        return method_exists($this->event, 'uniqueVia')
            ? $this->event->uniqueVia()
            : ApplicationContext::getContainer()->get(Cache::class);
    }
}
