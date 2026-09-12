<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Enums;

use Hypervel\Contracts\Support\Arrayable;

enum ArrayableStatus: string implements Arrayable
{
    case pending = 'pending';
    case done = 'done';

    /**
     * Get the status description.
     */
    public function description(): string
    {
        return match ($this) {
            self::pending => 'pending status description',
            self::done => 'done status description'
        };
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'description' => $this->description(),
        ];
    }
}
