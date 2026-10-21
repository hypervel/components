<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Tests\Saloon\Fixtures\Resources\UserBaseResource;

class ResourceConnector extends TestConnector
{
    /**
     * Get the user resource.
     */
    public function user(): UserBaseResource
    {
        return new UserBaseResource($this);
    }
}
