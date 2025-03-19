<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hyperf\Support\Traits\ForwardsCalls;
use Hypervel\Mail\Contracts\Attachable;

/**
 * @mixin Message
 */
class TextMessage
{
    use ForwardsCalls;

    /**
     * Create a new text message instance.
     */
    public function __construct(
        protected Message $message
    ) {
    }

    /**
     * Embed a file in the message and get the CID.
     */
    public function embed(Attachable|Attachment|string $file): string
    {
        return '';
    }

    /**
     * Embed in-memory data in the message and get the CID.
     */
    public function embedData(mixed $data, string $name, ?string $contentType = null): string
    {
        return '';
    }

    /**
     * Dynamically pass missing methods to the underlying message instance.
     */
    public function __call(string $method, array $parameters)
    {
        $result = $this->forwardCallTo($this->message, $method, $parameters);
        if ($result === $this->message) {
            return $this;
        }

        return $result;
    }
}
