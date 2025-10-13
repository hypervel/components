<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

class FakeListenerWithDynamicTags
{
    public function tags(FakeEvent $event)
    {
        return [
            'listenerTag1',
            get_class($event),
        ];
    }
}
