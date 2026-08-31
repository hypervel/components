<?php

declare(strict_types=1);

namespace Hypervel\Mail\Transport;

use Hypervel\Context\CoroutineContext;
use Hypervel\Support\Collection;
use Stringable;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class ArrayTransport implements Stringable, TransportInterface
{
    /**
     * The coroutine context key for array transport messages.
     */
    public const string MESSAGE_STORE_CONTEXT_KEY = '__mail.array_transport_messages';

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->messageStore()->messagesFor($this)[] = new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    /**
     * Retrieve the collection of messages.
     */
    public function messages(): Collection
    {
        return $this->messageStore()->messagesFor($this);
    }

    /**
     * Clear all of the messages from the local collection.
     */
    public function flush(): Collection
    {
        return $this->messageStore()->flush($this);
    }

    /**
     * Retrieve the current message store.
     */
    protected function messageStore(): ArrayTransportMessageStore
    {
        /** @var ArrayTransportMessageStore */
        return CoroutineContext::getOrSet(
            self::MESSAGE_STORE_CONTEXT_KEY,
            fn () => new ArrayTransportMessageStore,
        );
    }

    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'array';
    }
}
