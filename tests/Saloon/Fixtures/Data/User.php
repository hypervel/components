<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Data;

use Hypervel\Saloon\Http\Response;

class User
{
    /**
     * Create a new user.
     */
    public function __construct(
        public string $name,
        public string $actualName,
        public string $twitter,
    ) {
    }

    /**
     * Create a user from a Saloon response.
     */
    public static function fromSaloon(Response $response): static
    {
        $data = $response->json();

        return new static($data['name'], $data['actual_name'], $data['twitter']);
    }
}
