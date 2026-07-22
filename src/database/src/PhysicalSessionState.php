<?php

declare(strict_types=1);

namespace Hypervel\Database;

/**
 * @internal
 */
final class PhysicalSessionState
{
    /**
     * @var array<int, string>
     */
    public array $appliedStates = [];

    public bool $configuring = false;

    public bool $unknown = false;
}
