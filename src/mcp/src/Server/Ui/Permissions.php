<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Ui;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Mcp\Server\Ui\Enums\Permission;
use stdClass;

/**
 * @implements Arrayable<array<string, stdClass>>
 */
class Permissions implements Arrayable
{
    /** @var array<int, Permission> */
    protected array $enabled = [];

    /**
     * Create new application permissions.
     */
    public static function make(): static
    {
        return new static;
    }

    /**
     * Request camera access.
     */
    public function camera(): static
    {
        return $this->allow(Permission::Camera);
    }

    /**
     * Request microphone access.
     */
    public function microphone(): static
    {
        return $this->allow(Permission::Microphone);
    }

    /**
     * Request location access.
     */
    public function geolocation(): static
    {
        return $this->allow(Permission::Geolocation);
    }

    /**
     * Request clipboard write access.
     */
    public function clipboardWrite(): static
    {
        return $this->allow(Permission::ClipboardWrite);
    }

    /**
     * Add the requested permissions.
     */
    public function allow(Permission ...$permissions): static
    {
        array_push($this->enabled, ...$permissions);

        return $this;
    }

    /**
     * Convert permissions to an array.
     *
     * @return array<string, stdClass>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->enabled as $permission) {
            $result[$permission->value] = new stdClass;
        }

        return $result;
    }
}
