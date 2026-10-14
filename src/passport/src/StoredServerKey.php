<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DateTimeInterface;
use SensitiveParameter;

/**
 * Preserve the backing row needed to extend retention when this key signs.
 */
readonly class StoredServerKey extends ServerKey
{
    /**
     * Create a server key from stored key material and its retention bound.
     */
    public function __construct(
        public string $id,
        public int $maxTokenLifetime,
        string $publicKey,
        #[SensitiveParameter]
        ?string $privateKey,
        DateTimeInterface $activatesAt,
    ) {
        parent::__construct($publicKey, $privateKey, $activatesAt);
    }
}
