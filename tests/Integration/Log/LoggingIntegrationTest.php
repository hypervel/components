<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Log;

use Hypervel\Log\Events\MessageLogged;
use Hypervel\Log\Logger;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Log;
use Hypervel\Testbench\TestCase;

class LoggingIntegrationTest extends TestCase
{
    public function testLoggingCanBeRunWithoutEncounteringExceptions()
    {
        $this->expectNotToPerformAssertions();

        Log::info('Hello World');
    }

    public function testCallingLoggerDirectlyDispatchesOneEvent()
    {
        Event::fake([MessageLogged::class]);

        $this->app->make(Logger::class)->debug('my debug message');

        Event::assertDispatchedTimes(MessageLogged::class, 1);
    }
}
