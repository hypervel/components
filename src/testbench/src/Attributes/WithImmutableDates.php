<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Carbon\CarbonImmutable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\Contracts\Attributes\AfterEach;
use Hypervel\Testbench\Contracts\Attributes\BeforeEach;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class WithImmutableDates implements AfterEach, BeforeEach
{
    public function beforeEach(ApplicationContract $app): void
    {
        Date::useClass(CarbonImmutable::class);
    }

    public function afterEach(ApplicationContract $app): void
    {
        Date::useDefault();
    }
}
