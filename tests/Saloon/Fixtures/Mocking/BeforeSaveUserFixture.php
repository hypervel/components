<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Mocking;

use Hypervel\Saloon\Data\RecordedResponse;
use Hypervel\Saloon\Http\Faking\Fixture;

class BeforeSaveUserFixture extends Fixture
{
    /**
     * Define the name of the fixture.
     */
    protected function defineName(): string
    {
        return 'user';
    }

    /**
     * Modify the fixture before it is sent.
     */
    protected function beforeSave(RecordedResponse $recordedResponse): RecordedResponse
    {
        $recordedResponse->statusCode = 222;

        return $recordedResponse;
    }
}
