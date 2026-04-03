<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Hypervel\Support\Facades\Bus;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Jobs\RegisterUser;

/**
 * @internal
 * @coversNothing
 */
class DispatchJobTest extends TestCase
{
    #[Test]
    public function itCanTriggersExpectedJobs()
    {
        Bus::fake();

        dispatch(new RegisterUser());

        Bus::assertDispatched(RegisterUser::class);
    }
}
