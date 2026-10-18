<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Bedrock;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\CredentialsInterface;
use Closure;
use Hypervel\Coroutine\Locker;
use Hypervel\Database\ConnectionResolver;

/**
 * Share completed credentials across one provider's gateways without sharing a pending AWS promise.
 *
 * @internal
 */
class BedrockCredentials
{
    protected ?CredentialsInterface $credentials = null;

    /**
     * Resolve credentials with one owner for each refresh.
     *
     * @param Closure(): CredentialsInterface $resolve
     */
    public function get(Closure $resolve, ?int $timeout = null): CredentialsInterface
    {
        if ($this->isFresh()) {
            return $this->credentials;
        }

        ConnectionResolver::releaseIdleConnections();
        $key = '__ai.bedrock_credentials.' . spl_object_id($this);

        while (true) {
            if (Locker::lock($key, $timeout ?? -1)) {
                try {
                    if ($this->isFresh()) {
                        return $this->credentials;
                    }

                    // A failed refresh must not wake waiters with the old credentials.
                    $this->credentials = null;

                    return $this->credentials = $resolve();
                } finally {
                    Locker::unlock($key);
                }
            }

            // Freshly issued short-lived credentials may already be inside AWS's refresh window.
            if ($this->credentials !== null && ! $this->credentials->isExpired()) {
                return $this->credentials;
            }
        }
    }

    /**
     * Determine whether the credentials can be reused without refreshing.
     *
     * @phpstan-impure
     */
    protected function isFresh(): bool
    {
        if ($this->credentials === null) {
            return false;
        }

        $expiration = $this->credentials->getExpiration();

        return $expiration === null || $expiration - time() > CredentialProvider::REFRESH_WINDOW;
    }
}
