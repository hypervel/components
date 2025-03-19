<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access;

trait HandlesAuthorization
{
    /**
     * Create a new access response.
     */
    protected function allow(?string $message = null, null|int|string $code = null): Response
    {
        return Response::allow($message, $code);
    }

    /**
     * Throws an unauthorized exception.
     */
    protected function deny(?string $message = null, null|int|string $code = null): Response
    {
        return Response::deny($message, $code);
    }

    /**
     * Deny with a HTTP status code.
     */
    public function denyWithStatus(int $status, ?string $message = null, null|int|string $code = null): Response
    {
        return Response::denyWithStatus($status, $message, $code);
    }

    /**
     * Deny with a 404 HTTP status code.
     */
    public function denyAsNotFound(?string $message = null, null|int|string $code = null): Response
    {
        return Response::denyWithStatus(404, $message, $code);
    }
}
