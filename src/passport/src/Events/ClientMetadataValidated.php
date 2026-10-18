<?php

declare(strict_types=1);

namespace Hypervel\Passport\Events;

use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Client;

class ClientMetadataValidated
{
    /** @var array<string, mixed> */
    public array $extensions = [];

    /**
     * Create a synchronous event for validating additional registration metadata.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $validated
     */
    public function __construct(
        public readonly array $submitted,
        public readonly array $validated,
        public readonly ?Client $client,
        public readonly ?AccessToken $token,
    ) {
    }
}
