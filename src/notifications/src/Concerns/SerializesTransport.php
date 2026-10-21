<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Concerns;

use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Support\TransportSnapshot;
use Hypervel\Queue\SerializesModels;
use Throwable;

trait SerializesTransport
{
    use SerializesModels {
        __serialize as protected serializeModels;
        __unserialize as protected unserializeModels;
    }

    /**
     * Prepare notification models and transport state for serialization.
     */
    public function __serialize(): array
    {
        $values = $this->serializeModels();

        // HTTP bodies contain streams PHP cannot serialize. Restore the normal objects
        // before invoking listeners so queued and synchronous listeners share the same APIs.
        if ($this instanceof NotificationFailed) {
            if (($values['data']['exception'] ?? null) instanceof Throwable) {
                $values['data']['exception'] = TransportSnapshot::capture($values['data']['exception']);
            }
        } elseif (array_key_exists('response', $values)) {
            $values['response'] = TransportSnapshot::capture($values['response']);
        }

        return $values;
    }

    /**
     * Restore notification transport objects and models.
     */
    public function __unserialize(array $values): void
    {
        if ($this instanceof NotificationFailed) {
            if (($values['data']['exception'] ?? null) instanceof TransportSnapshot) {
                $values['data']['exception'] = $values['data']['exception']->restore();
            }
        } elseif (($values['response'] ?? null) instanceof TransportSnapshot) {
            $values['response'] = $values['response']->restore();
        }

        $this->unserializeModels($values);
    }
}
