<?php

declare(strict_types=1);

namespace Hypervel\Routing\Exceptions;

use Hypervel\Http\Response;
use RuntimeException;
use Throwable;

class StreamedResponseException extends RuntimeException
{
    /**
     * The actual exception thrown during the stream.
     */
    public Throwable $originalException;

    /**
     * Create a new exception instance.
     */
    public function __construct(Throwable $originalException)
    {
        $this->originalException = $originalException;

        parent::__construct($originalException->getMessage());
    }

    /**
     * Render the exception.
     */
    public function render(): Response
    {
        return new Response('');
    }

    /**
     * Get the actual exception thrown during the stream.
     */
    public function getInnerException(): Throwable
    {
        return $this->originalException;
    }
}
