<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Concerns;

use Hypervel\Reverb\Contracts\ApplicationProvider;

trait SerializesConnections
{
    /**
     * Prepare the connection instance values for serialization.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id(),
            'identifier' => $this->identifier(),
            'application' => $this->app()->id(),
            'origin' => $this->origin(),
            'lastSeenAt' => $this->lastSeenAt,
            'hasBeenPinged' => $this->hasBeenPinged,
        ];
    }

    /**
     * Restore the connection after serialization.
     */
    public function __unserialize(array $values): void
    {
        $this->id = $values['id'];
        $this->identifier = $values['identifier'];
        $this->application = app(ApplicationProvider::class)->findById($values['application']);
        $this->origin = $values['origin'];
        $this->lastSeenAt = $values['lastSeenAt'];
        $this->hasBeenPinged = $values['hasBeenPinged'];
    }
}
