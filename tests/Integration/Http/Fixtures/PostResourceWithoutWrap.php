<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

class PostResourceWithoutWrap extends PostResource
{
    public static ?string $wrap = null;
}
