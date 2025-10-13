<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

class FakeEvent
{
    public function tags()
    {
        return [
            'eventTag1',
            'eventTag2',
        ];
    }
}
