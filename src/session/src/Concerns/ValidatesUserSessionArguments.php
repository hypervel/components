<?php

declare(strict_types=1);

namespace Hypervel\Session\Concerns;

use Hypervel\Session\SessionId;
use InvalidArgumentException;

trait ValidatesUserSessionArguments
{
    /**
     * Validate an authentication provider supplied to the handler.
     */
    protected function validateAuthProvider(string $authProvider): void
    {
        if ($authProvider === '') {
            throw new InvalidArgumentException('The authentication provider may not be empty.');
        }
    }

    /**
     * Validate and deduplicate session identifiers supplied to the handler.
     *
     * @param list<string> $sessionIds
     * @return list<string>
     */
    protected function normalizeSessionIds(array $sessionIds): array
    {
        foreach ($sessionIds as $sessionId) {
            SessionId::validate($sessionId);
        }

        return array_values(array_unique($sessionIds));
    }
}
