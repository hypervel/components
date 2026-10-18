<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

class EmbeddingsCountMismatchException extends AiException
{
    /**
     * Create an embeddings count mismatch exception.
     */
    public function __construct(public readonly int $expected, public readonly int $actual)
    {
        parent::__construct(sprintf('Provider returned %d embeddings for %d inputs.', $actual, $expected));
    }
}
