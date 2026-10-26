<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

use Throwable;

class OAuthAccountReconnectRequiredException extends SaloonException
{
    /**
     * Create an exception for expired credentials that cannot be refreshed.
     */
    public function __construct(?string $message = null, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message ?? 'The OAuth account token has expired and cannot be refreshed. Reconnect the account.', $code, $previous);
    }
}
