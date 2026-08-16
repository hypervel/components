<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Session\Contracts\CanManageUserSessions;
use Hypervel\Support\Collection;

class UserSessions
{
    /**
     * Create a new user session repository.
     */
    public function __construct(
        protected string $authProvider,
        protected string $userId,
        protected CanManageUserSessions $handler,
        protected Store $store,
    ) {
    }

    /**
     * Get the active sessions for the user.
     *
     * @return Collection<int, UserSession>
     */
    public function all(): Collection
    {
        return $this->handler->userSessions($this->authProvider, $this->userId);
    }

    /**
     * Invalidate an active session belonging to the user.
     */
    public function invalidate(string $sessionId): bool
    {
        SessionId::validate($sessionId);

        $currentSessionId = $this->store->isStarted()
            ? $this->store->getId()
            : null;

        $destroyed = $this->handler->destroyUserSession(
            $this->authProvider,
            $this->userId,
            $sessionId,
        );

        if ($destroyed && $sessionId === $currentSessionId) {
            $this->rotateCurrentSession();
        }

        return $destroyed;
    }

    /**
     * Invalidate every other active session belonging to the user.
     */
    public function invalidateOthers(string $except): int
    {
        SessionId::validate($except);

        return $this->invalidateMany($except);
    }

    /**
     * Invalidate every active session belonging to the user.
     */
    public function invalidateAll(): int
    {
        return $this->invalidateMany();
    }

    /**
     * Invalidate active sessions while preserving an optional session identifier.
     */
    protected function invalidateMany(?string $except = null): int
    {
        $currentSessionId = $this->store->isStarted()
            ? $this->store->getId()
            : null;
        $destroyedCurrentSession = false;

        if ($currentSessionId !== null && $currentSessionId !== $except) {
            $destroyedCurrentSession = $this->handler->destroyUserSession(
                $this->authProvider,
                $this->userId,
                $currentSessionId,
            );

            if ($destroyedCurrentSession) {
                $this->rotateCurrentSession();
            }
        }

        $exceptSessionIds = array_values(array_unique(array_filter(
            [$except, $currentSessionId],
            static fn (?string $sessionId): bool => $sessionId !== null,
        )));

        return $this->handler->destroyUserSessions(
            $this->authProvider,
            $this->userId,
            $exceptSessionIds,
        ) + (int) $destroyedCurrentSession;
    }

    /**
     * Rotate the current session after its stored record was destroyed.
     */
    protected function rotateCurrentSession(): void
    {
        $this->store->flush();
        $this->store->regenerate();

        UserSessionIdentity::suppress($this->store->getId());
    }
}
