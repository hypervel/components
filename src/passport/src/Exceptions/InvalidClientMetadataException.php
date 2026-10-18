<?php

declare(strict_types=1);

namespace Hypervel\Passport\Exceptions;

use RuntimeException;

class InvalidClientMetadataException extends RuntimeException
{
    /**
     * Create a client registration error usable by HTTP and application callers.
     */
    public function __construct(public readonly string $error, string $description)
    {
        parent::__construct($description);
    }

    /**
     * Reject invalid client metadata.
     */
    public static function invalidClientMetadata(string $description): static
    {
        return new static('invalid_client_metadata', $description);
    }

    /**
     * Reject an invalid redirect URI.
     */
    public static function invalidRedirectUri(string $description): static
    {
        return new static('invalid_redirect_uri', $description);
    }
}
