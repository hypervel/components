<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

interface CastsRequestInput
{
    /**
     * Cast the given validated request input.
     *
     * @param array<array-key, mixed> $input
     */
    public function cast(string $key, mixed $value, array $input): mixed;
}
