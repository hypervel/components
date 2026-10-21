<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Fixtures;

use RuntimeException;
use Serializable;

class LegacySerializableTransportException extends RuntimeException implements Serializable
{
    public bool $restored = false;

    /**
     * Serialize the exception using its native custom contract.
     */
    public function serialize(): string
    {
        return serialize($this->getMessage());
    }

    /**
     * Restore the custom exception state.
     */
    public function unserialize(string $data): void
    {
        $this->message = unserialize($data);
        $this->restored = true;
    }
}
