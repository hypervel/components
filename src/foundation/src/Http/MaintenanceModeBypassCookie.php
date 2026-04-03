<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use Hypervel\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

class MaintenanceModeBypassCookie
{
    /**
     * Create a new maintenance mode bypass cookie.
     */
    public static function create(string $key): Cookie
    {
        $expiresAt = Carbon::now()->addHours(12);

        return new Cookie('hypervel_maintenance', base64_encode(json_encode([
            'expires_at' => $expiresAt->getTimestamp(),
            'mac' => hash_hmac('sha256', (string) $expiresAt->getTimestamp(), $key),
        ])), $expiresAt, config('session.path'), config('session.domain'));
    }

    /**
     * Determine if the given maintenance mode bypass cookie is valid.
     */
    public static function isValid(string $cookie, string $key): bool
    {
        $payload = json_decode(base64_decode($cookie), true);

        return is_array($payload)
            && is_numeric($payload['expires_at'] ?? null)
            && isset($payload['mac'])
            && hash_equals(hash_hmac('sha256', (string) $payload['expires_at'], $key), $payload['mac'])
            && (int) $payload['expires_at'] >= Carbon::now()->getTimestamp();
    }
}
