<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

class FakeJobWithTagsMethod
{
    public function tags()
    {
        return [
            'first',
            'second',
        ];
    }
}
