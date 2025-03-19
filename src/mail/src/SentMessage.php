<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hyperf\Collection\Collection;
use Hyperf\Support\Traits\ForwardsCalls;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;

/**
 * @mixin \Symfony\Component\Mailer\SentMessage
 */
class SentMessage
{
    use ForwardsCalls;

    /**
     * Create a new SentMessage instance.
     */
    public function __construct(
        protected SymfonySentMessage $sentMessage
    ) {
    }

    /**
     * Get the underlying Symfony Email instance.
     */
    public function getSymfonySentMessage(): SymfonySentMessage
    {
        return $this->sentMessage;
    }

    /**
     * Dynamically pass missing methods to the Symfony instance.
     */
    public function __call(string $method, array $parameters)
    {
        $result = $this->forwardCallTo($this->sentMessage, $method, $parameters);
        if ($result === $this->sentMessage) {
            return $this;
        }

        return $result;
    }

    /**
     * Get the serializable representation of the object.
     */
    public function __serialize(): array
    {
        $hasAttachments = Collection::make(
            $this->sentMessage->getOriginalMessage()->getAttachments() // @phpstan-ignore-line
        )->isNotEmpty();

        return [
            'hasAttachments' => $hasAttachments,
            'sentMessage' => $hasAttachments ? base64_encode(serialize($this->sentMessage)) : $this->sentMessage,
        ];
    }

    /**
     * Marshal the object from its serialized data.
     */
    public function __unserialize(array $data)
    {
        $hasAttachments = ($data['hasAttachments'] ?? false) === true;

        $this->sentMessage = $hasAttachments ? unserialize(base64_decode($data['sentMessage'])) : $data['sentMessage'];
    }
}
