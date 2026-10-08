<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Resources;

use Hypervel\Saloon\Http\BaseResource;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class UserBaseResource extends BaseResource
{
    /**
     * Get User.
     */
    public function get(): array
    {
        return $this->connector->send(new UserRequest)->json();
    }
}
