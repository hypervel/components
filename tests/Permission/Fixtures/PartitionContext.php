<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures;

use Hypervel\Support\Facades\Context;

class PartitionContext
{
    public const string KEY = 'permission_workspace_id';

    /**
     * Set the current permission test partition.
     */
    public static function set(int|string $partition): void
    {
        Context::add(self::KEY, $partition);
    }

    /**
     * Resolve the current permission test partition.
     */
    public static function get(): int|string|null
    {
        $partition = Context::get(self::KEY);

        return is_int($partition) || is_string($partition) ? $partition : null;
    }

    /**
     * Forget the current permission test partition.
     */
    public static function forget(): void
    {
        Context::forget(self::KEY);
    }
}
