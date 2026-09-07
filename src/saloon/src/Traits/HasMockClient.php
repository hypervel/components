<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits;

use Hypervel\Saloon\Http\Faking\MockClient;

trait HasMockClient
{
    /**
     * The operation-owned mock client.
     */
    protected ?MockClient $mockClient = null;

    /**
     * Use a mock client for this request.
     *
     * @return $this
     */
    public function withMockClient(MockClient $mockClient): static
    {
        $this->mockClient = $mockClient;

        return $this;
    }

    /**
     * Get the mock client.
     */
    public function mockClient(): ?MockClient
    {
        return $this->mockClient;
    }

    /**
     * Determine if the request has a mock client.
     */
    public function hasMockClient(): bool
    {
        return $this->mockClient instanceof MockClient;
    }
}
