<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Data;

use Hypervel\Saloon\Http\Response;

class ApiResponse
{
    /**
     * Create a new API response.
     */
    public function __construct(
        public array $data,
    ) {
    }

    /**
     * Create an API response from a Saloon response.
     */
    public static function fromSaloon(Response $response): static
    {
        return new static($response->json());
    }
}
