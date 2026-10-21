<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Responses;

use Hypervel\Saloon\Http\Response;

class UserResponse extends Response
{
    /**
     * Cast the response to user data.
     */
    public function customCastMethod(): UserData
    {
        return new UserData($this->json('foo'));
    }

    /**
     * Get the foo value.
     */
    public function foo(): ?string
    {
        return $this->json('foo');
    }
}
