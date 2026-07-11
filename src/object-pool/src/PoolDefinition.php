<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use InvalidArgumentException;

final readonly class PoolDefinition
{
    /**
     * Create an immutable pool definition.
     */
    public function __construct(
        public string $identity,
        public string $resourceType,
        public string $fingerprint,
        public PoolOptions $options,
    ) {
        if (trim($this->identity) === '') {
            throw new InvalidArgumentException('The pool identity must be a non-empty string.');
        }

        if (trim($this->resourceType) === '') {
            throw new InvalidArgumentException('The pool resource type must be a non-empty string.');
        }

        if (trim($this->fingerprint) === '') {
            throw new InvalidArgumentException('The pool fingerprint must be a non-empty string.');
        }
    }
}
