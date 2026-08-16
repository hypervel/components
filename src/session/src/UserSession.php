<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Support\CarbonImmutable;

final readonly class UserSession
{
    /**
     * Create a new user session instance.
     */
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        public ?string $userAgent,
        public CarbonImmutable $lastActivity,
        public CarbonImmutable $expiresAt,
    ) {
    }
}
