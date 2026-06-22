<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Support\Logger;
use Hypervel\Tests\TestCase;

class LoggerTest extends TestCase
{
    public function testDoesNotThrowWhenConstructedWithoutSocket(): void
    {
        $logger = new Logger('abc123');

        $logger->line('hello');
        $logger->partial('streamed ');
        $logger->commitPartial();
        $logger->success('done');
        $logger->warning('careful');
        $logger->error('broken');
        $logger->label('Updated');
        $logger->subLabel('detail');

        $this->addToAssertionCount(1);
    }
}
