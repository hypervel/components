<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SourceFiles;

use Hypervel\Support\Attributes\Unsupported;

class MarkedMethod
{
    #[Unsupported(Visibility::Public->name)]
    public function handle(): void
    {
    }
}
