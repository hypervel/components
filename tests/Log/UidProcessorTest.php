<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Engine\Channel;
use Hypervel\Log\Processors\UidProcessor;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

class UidProcessorTest extends TestCase
{
    public function testSiblingRequestsReceiveDifferentUids(): void
    {
        $processor = new UidProcessor(16);

        $uids = parallel([
            fn (): string => $processor->getUid(),
            fn (): string => $processor->getUid(),
        ]);

        $this->assertNotSame($uids[0], $uids[1]);
        $this->assertSame(16, strlen($uids[0]));
        $this->assertSame(16, strlen($uids[1]));
    }

    public function testCopiedCoroutineKeepsTheRequestUid(): void
    {
        $processor = new UidProcessor;
        $parentUid = $processor->getUid();
        $result = new Channel(1);

        go(fn () => $result->push($processor->getUid()), copyContext: true);

        $this->assertSame($parentUid, $result->pop());
    }

    public function testResetOnlyReplacesTheCurrentCoroutineUid(): void
    {
        $processor = new UidProcessor;
        $first = $processor->getUid();

        $processor->reset();

        $this->assertNotSame($first, $processor->getUid());
    }
}
