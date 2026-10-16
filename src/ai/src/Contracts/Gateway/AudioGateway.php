<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Responses\AudioResponse;

interface AudioGateway
{
    /**
     * Generate audio from the given text.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse;
}
