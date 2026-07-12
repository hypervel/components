<?php

declare(strict_types=1);

namespace Hypervel\Database;

use InvalidArgumentException;

final readonly class ConnectionName
{
    public const READ = 'read';

    public const WRITE = 'write';

    /**
     * Create a new parsed connection name instance.
     */
    public function __construct(
        public string $requested,
        public string $base,
        public ?string $role = null
    ) {
    }

    /**
     * Parse a database connection name.
     */
    public static function parse(string $name): self
    {
        foreach ([self::READ, self::WRITE] as $role) {
            $suffix = '::' . $role;

            if (str_ends_with($name, $suffix)) {
                return new self($name, substr($name, 0, -strlen($suffix)), $role);
            }
        }

        // Laravel's ::direct suffix is intentionally omitted. Hypervel uses
        // normal named connections plus migrations_connection for pooler paths.
        if (str_ends_with($name, '::direct')) {
            throw new InvalidArgumentException(
                'Database connection suffix [::direct] is not supported. Configure a direct connection and use migrations_connection instead.'
            );
        }

        return new self($name, $name);
    }

    /**
     * Determine if the parsed name requests the read side.
     */
    public function isRead(): bool
    {
        return $this->role === self::READ;
    }

    /**
     * Determine if the parsed name requests the write side.
     */
    public function isWrite(): bool
    {
        return $this->role === self::WRITE;
    }
}
