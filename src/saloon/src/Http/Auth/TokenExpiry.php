<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use DateTimeImmutable;
use Hypervel\Support\Facades\Date;

class TokenExpiry
{
    /**
     * Calculate a fixed renewal deadline when installing credentials.
     */
    public static function refreshAt(?DateTimeImmutable $expiresAt): ?int
    {
        if ($expiresAt === null) {
            return null;
        }

        $expiry = $expiresAt->getTimestamp();
        $remaining = max(0, $expiry - Date::now()->getTimestamp());

        return $expiry - min(30, intdiv($remaining, 2));
    }
}
