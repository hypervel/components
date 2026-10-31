<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Queue\Attributes\UniqueFor;

class UniqueBroadcastEvent extends BroadcastEvent implements ShouldBeUnique
{
    /**
     * The unique lock identifier.
     */
    public string $uniqueId = '';

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 0;

    /**
     * Create a new event instance.
     */
    public function __construct(mixed $event)
    {
        if (method_exists($event, 'uniqueId')) {
            $this->uniqueId .= $event->uniqueId();
        } elseif (property_exists($event, 'uniqueId')) {
            $this->uniqueId .= $event->uniqueId;
        }

        if (method_exists($event, 'uniqueFor')) {
            $this->uniqueFor = $event->uniqueFor();
        } else {
            $this->uniqueFor = $this->getAttributeValue($event, UniqueFor::class, 'uniqueFor', $this->uniqueFor);
        }

        parent::__construct($event);
    }

    /**
     * Resolve the cache implementation that should manage the event's uniqueness.
     */
    public function uniqueVia(): ?Repository
    {
        return method_exists($this->event, 'uniqueVia')
            ? $this->event->uniqueVia()
            : Container::getInstance()->make(Repository::class);
    }
}
