<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Cookie\QueueingFactory;
use Hypervel\Http\Request;
use Hypervel\Session\CookieSessionHandler;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class CookieSessionHandlerCoroutineSafetyTest extends TestCase
{
    public function testCookieSessionHandlerRequestIsCoroutineIsolated(): void
    {
        $handler = new CookieSessionHandler(
            m::mock(QueueingFactory::class),
            120
        );

        [$resultA, $resultB] = parallel([
            function () use ($handler): string {
                $handler->setRequest(Request::create('/', 'GET', [], [
                    'session-a' => json_encode([
                        'data' => 'payload-a',
                        'expires' => time() + 60,
                    ]),
                ]));

                usleep(5000);

                return $handler->read('session-a');
            },
            function () use ($handler): string {
                usleep(2500);

                $handler->setRequest(Request::create('/', 'GET', [], [
                    'session-b' => json_encode([
                        'data' => 'payload-b',
                        'expires' => time() + 60,
                    ]),
                ]));

                usleep(5000);

                return $handler->read('session-b');
            },
        ]);

        $this->assertSame('payload-a', $resultA);
        $this->assertSame('payload-b', $resultB);
    }
}
