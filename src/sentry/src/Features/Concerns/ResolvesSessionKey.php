<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Concerns;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Session\Session;
use Throwable;

trait ResolvesSessionKey
{
    private const SESSION_KEY_RESOLUTION_CONTEXT_KEY = '__sentry.session_key.resolving';

    private const SESSION_KEY_PLACEHOLDER = '{sessionKey}';

    /**
     * Retrieve the current session key if available.
     */
    private function getSessionKey(): ?string
    {
        if (! $this->detectSessionKeyOnConsole && app()->runningInConsole()) {
            return null;
        }

        try {
            if ($this->container->resolved('session.store')) {
                return $this->resolvedSessionKey();
            }

            $request = RequestContext::getOrNull();

            if ($request !== null) {
                $cookieName = $this->container->make('config')->string('session.cookie');
                $sessionKey = $request->cookies->get($cookieName);

                if (is_string($sessionKey)) {
                    return $sessionKey;
                }
            }

            if (CoroutineContext::get(self::SESSION_KEY_RESOLUTION_CONTEXT_KEY, false) === true) {
                return null;
            }

            CoroutineContext::set(self::SESSION_KEY_RESOLUTION_CONTEXT_KEY, true);

            try {
                return $this->resolvedSessionKey();
            } finally {
                CoroutineContext::forget(self::SESSION_KEY_RESOLUTION_CONTEXT_KEY);
            }
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Retrieve the session key from the session store.
     */
    private function resolvedSessionKey(): ?string
    {
        /** @var Session $sessionStore */
        $sessionStore = $this->container->make('session.store');

        return $sessionStore->getId();
    }

    /**
     * Replace a session key with a placeholder.
     */
    private function replaceSessionKey(string $value): string
    {
        return $value === $this->getSessionKey() ? self::SESSION_KEY_PLACEHOLDER : $value;
    }

    /**
     * Replace session keys in an array of keys with placeholders.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function replaceSessionKeys(array $values): array
    {
        // Resolve once per command; non-string parameters, including null, must pass through unchanged.
        $sessionKey = $this->getSessionKey();

        return array_map(
            static fn (mixed $value): mixed => is_string($value) && $value === $sessionKey
                ? self::SESSION_KEY_PLACEHOLDER
                : $value,
            $values
        );
    }
}
