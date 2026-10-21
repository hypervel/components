<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Mocking;

use Hypervel\Saloon\Http\Faking\Fixture;

class UserFixture extends Fixture
{
    /**
     * Define the name of the fixture.
     */
    protected function defineName(): string
    {
        return 'user';
    }
}
