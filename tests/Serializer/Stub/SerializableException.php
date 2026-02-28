<?php

declare(strict_types=1);

namespace Hypervel\Tests\Serializer\Stub;

use RuntimeException;

class SerializableException extends RuntimeException
{
    public function __unserialize(array $data): void
    {
        [$this->message, $this->code, $this->file, $this->line] = $data;
    }

    public function __serialize(): array
    {
        return [$this->message, $this->code, $this->file, $this->line];
    }
}
