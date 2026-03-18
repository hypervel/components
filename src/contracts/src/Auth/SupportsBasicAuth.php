<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Auth;

use Symfony\Component\HttpFoundation\Response;

interface SupportsBasicAuth
{
    /**
     * Attempt to authenticate using HTTP Basic Auth.
     */
    public function basic(string $field = 'email', array $extraConditions = []): ?Response;

    /**
     * Perform a stateless HTTP Basic login attempt.
     */
    public function onceBasic(string $field = 'email', array $extraConditions = []): ?Response;
}
