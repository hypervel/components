<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\HttpTransportPool;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Sentry\Event;
use Sentry\Options;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;

class HttpPoolTransportNonCoroutineTest extends TestCase
{
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    public function testRootCoroutineOwnsAndReleasesTheTransport(): void
    {
        $httpTransport = Double::for(HttpTransport::class)->strict();
        $httpTransport->expects('send')
            ->returns(new Result(ResultStatus::success()));
        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')->returns($httpTransport);
        $pool->expects('release')->with($httpTransport);
        $transport = new HttpPoolTransport($pool, new Options(['dsn' => 'https://public@example.com/1']));
        $event = Event::createEvent();

        $result = $transport->send($event);

        $this->assertSame(ResultStatus::success(), $result->getStatus());
        $this->assertSame($event, $result->getEvent());
        $this->assertSame(ResultStatus::success(), $transport->close()->getStatus());
    }
}
