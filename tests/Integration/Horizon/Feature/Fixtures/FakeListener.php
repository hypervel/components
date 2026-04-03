<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures;

class FakeListener
{
    public function tags()
    {
        return [
            'listenerTag1',
            'listenerTag2',
        ];
    }
}
