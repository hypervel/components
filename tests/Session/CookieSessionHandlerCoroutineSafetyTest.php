<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Cookie\CookieJar;
use Hypervel\Http\Request;
use Hypervel\Session\CookieSessionHandler;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;

class CookieSessionHandlerCoroutineSafetyTest extends TestCase
{
    public function testCookieSessionHandlerRequestIsCoroutineIsolated(): void
    {
        $handler = new CookieSessionHandler(
            new CookieJar,
            120
        );

        [$resultA, $resultB] = parallel([
            function () use ($handler): string {
                $handler->setRequest(Request::create('/', 'GET', [], [
                    'session-a' => serialize([
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
                    'session-b' => serialize([
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
