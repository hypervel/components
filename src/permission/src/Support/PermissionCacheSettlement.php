<?php

declare(strict_types=1);

namespace Hypervel\Permission\Support;

/**
 * Track registration and ownership for one permission cache settlement.
 */
class PermissionCacheSettlement
{
    public bool $callbackRanImmediately = false;

    public bool $deferred = false;

    public bool $ownsToken = false;

    public ?string $provisionalToken = null;

    /**
     * Create a permission cache settlement.
     */
    public function __construct(public readonly string $connectionName)
    {
    }
}
