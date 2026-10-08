<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Responses;

use Hypervel\Ai\Responses\Data\Step;

class SubclassedStep extends Step
{
    private string $secret = 'default';

    /**
     * Remember a private value.
     */
    public function rememberSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    /**
     * Get the private value.
     */
    public function secret(): string
    {
        return $this->secret;
    }
}
