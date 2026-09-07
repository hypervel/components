<?php

declare(strict_types=1);

namespace Hypervel\Session\Contracts;

use Hypervel\Session\UserSession;
use Hypervel\Support\Collection;

interface CanManageUserSessions
{
    /**
     * Determine if the handler supports user session management.
     */
    public function supportsUserSessionManagement(): bool;

    /**
     * Get the active sessions for the given user.
     *
     * @return Collection<int, UserSession>
     */
    public function userSessions(string $authProvider, int|string $userId): Collection;

    /**
     * Destroy an active session belonging to the given user.
     */
    public function destroyUserSession(
        string $authProvider,
        int|string $userId,
        string $sessionId,
    ): bool;

    /**
     * Destroy the active sessions belonging to the given user.
     *
     * @param list<string> $except
     */
    public function destroyUserSessions(
        string $authProvider,
        int|string $userId,
        array $except = [],
    ): int;
}
