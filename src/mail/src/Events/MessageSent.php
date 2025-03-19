<?php

declare(strict_types=1);

namespace Hypervel\Mail\Events;

use Exception;
use Hyperf\Collection\Collection;
use Hypervel\Mail\SentMessage;

class MessageSent
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public SentMessage $sent,
        public array $data = []
    ) {
    }

    /**
     * Get the serializable representation of the object.
     */
    public function __serialize(): array
    {
        /* @phpstan-ignore-next-line */
        $hasAttachments = Collection::make($this->message->getAttachments())->isNotEmpty();

        return [
            'sent' => $this->sent,
            'data' => $hasAttachments ? base64_encode(serialize($this->data)) : $this->data,
            'hasAttachments' => $hasAttachments,
        ];
    }

    /**
     * Marshal the object from its serialized data.
     */
    public function __unserialize(array $data)
    {
        $this->sent = $data['sent'];

        $this->data = (($data['hasAttachments'] ?? false) === true)
            ? unserialize(base64_decode($data['data']))
            : $data['data'];
    }

    /**
     * Dynamically get the original message.
     */
    public function __get(string $key)
    {
        if ($key === 'message') {
            return $this->sent->getOriginalMessage();
        }

        throw new Exception('Unable to access undefined property on ' . __CLASS__ . ': ' . $key);
    }
}
