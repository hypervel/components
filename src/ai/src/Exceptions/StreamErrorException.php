<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

use Hypervel\Ai\Streaming\Events\Error;

class StreamErrorException extends AiException
{
    /**
     * Create an exception for an incomplete or failed stream.
     */
    public function __construct(public readonly ?Error $error = null)
    {
        parent::__construct($error->message ?? 'The provider ended the stream without completing the step.');
    }
}
