<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DateTimeInterface;
use Hypervel\Support\CarbonImmutable;
use SensitiveParameter;

readonly class ServerKey
{
    public ?CarbonImmutable $activatesAt;

    /**
     * Define a signing or verification key using PEM contents or file paths.
     */
    public function __construct(
        public string $publicKey,
        #[SensitiveParameter]
        public ?string $privateKey = null,
        ?DateTimeInterface $activatesAt = null,
    ) {
        $this->activatesAt = $activatesAt === null ? null : CarbonImmutable::instance($activatesAt);
    }
}
