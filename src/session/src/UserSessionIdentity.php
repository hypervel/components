<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Auth\AuthManager;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use InvalidArgumentException;

final readonly class UserSessionIdentity
{
    private const string UNOWNED_CONTEXT_KEY = '__session.user_sessions.unowned';

    /**
     * Create a new user session identity state.
     */
    private function __construct(
        public ?string $authProvider,
        public ?string $userId,
        private bool $unowned,
    ) {
    }

    /**
     * Mark the given session identifier as unowned on subsequent writes.
     */
    public static function suppress(string $sessionId): void
    {
        /** @var array<string, true> $unownedSessionIds */
        $unownedSessionIds = CoroutineContext::get(self::UNOWNED_CONTEXT_KEY, []);
        $unownedSessionIds[$sessionId] = true;

        CoroutineContext::set(self::UNOWNED_CONTEXT_KEY, $unownedSessionIds);
    }

    /**
     * Normalize a user identifier for persistent storage.
     */
    public static function normalize(int|string $userId): string
    {
        $normalizedUserId = (string) $userId;

        if ($normalizedUserId === '') {
            throw new InvalidArgumentException('The user identifier may not be empty.');
        }

        return $normalizedUserId;
    }

    /**
     * Resolve the identity state for the given session identifier.
     *
     * Unresolved ownership is unknown and preserves a live association;
     * unowned is known-none and clears one.
     */
    public static function resolve(?Container $container, string $sessionId): self
    {
        /** @var array<string, true> $unownedSessionIds */
        $unownedSessionIds = CoroutineContext::get(self::UNOWNED_CONTEXT_KEY, []);

        if (isset($unownedSessionIds[$sessionId])) {
            return new self(null, null, true);
        }

        if ($container === null || ! $container->has('auth')) {
            return new self(null, null, false);
        }

        /** @var AuthManager $auth */
        $auth = $container->make('auth');
        $guard = $auth->getDefaultDriver();
        $userId = $auth->guard($guard)->id();

        if ($userId === null) {
            return new self(null, null, false);
        }

        $authProvider = $auth->getUserProviderName($guard);

        if ($authProvider === null) {
            return new self(null, null, true);
        }

        return new self(
            $authProvider,
            self::normalize($userId),
            false,
        );
    }

    /**
     * Determine if a user identity was resolved.
     */
    public function isResolved(): bool
    {
        return $this->authProvider !== null && $this->userId !== null;
    }

    /**
     * Determine if the identity is known to be unowned.
     */
    public function isUnowned(): bool
    {
        return $this->unowned;
    }
}
