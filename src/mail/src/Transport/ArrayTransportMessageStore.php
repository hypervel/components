<?php

declare(strict_types=1);

namespace Hypervel\Mail\Transport;

use Hypervel\Context\ReplicableContext;
use Hypervel\Support\Collection;
use WeakMap;

class ArrayTransportMessageStore implements ReplicableContext
{
    /**
     * The messages stored for each transport.
     *
     * @var WeakMap<ArrayTransport, Collection>
     */
    protected WeakMap $messages;

    /**
     * Create a new array transport message store.
     */
    public function __construct()
    {
        $this->messages = new WeakMap;
    }

    /**
     * Retrieve the messages for the given transport.
     */
    public function messagesFor(ArrayTransport $transport): Collection
    {
        return $this->messages[$transport] ??= new Collection;
    }

    /**
     * Clear the messages for the given transport.
     */
    public function flush(ArrayTransport $transport): Collection
    {
        return $this->messages[$transport] = new Collection;
    }

    /**
     * Create an independent copy with the same messages.
     */
    public function replicate(): static
    {
        $replica = new static;

        foreach ($this->messages as $transport => $messages) {
            $replica->messages[$transport] = clone $messages;
        }

        return $replica;
    }
}
