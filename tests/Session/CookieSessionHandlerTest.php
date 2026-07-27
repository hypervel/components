<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Cookie\QueueingFactory;
use Hypervel\Http\Request;
use Hypervel\Session\CookieSessionHandler;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

class CookieSessionHandlerTest extends TestCase
{
    public function testBinarySessionPayloadRoundTripsThroughCookieEnvelope(): void
    {
        $cookie = m::mock(QueueingFactory::class);
        $handler = new CookieSessionHandler($cookie, 120);
        $payload = serialize(['binary' => "\x00\xff\x10"]);
        $cookieValue = null;

        $cookie->shouldReceive('queue')
            ->once()
            ->withArgs(function (string $name, string $value, int $minutes) use (&$cookieValue): bool {
                $this->assertSame('session-id', $name);
                $this->assertSame(120, $minutes);
                $cookieValue = $value;

                return true;
            });

        $this->assertTrue($handler->write('session-id', $payload));
        $this->assertIsString($cookieValue);

        $handler->setRequest(Request::create('/', cookies: ['session-id' => $cookieValue]));

        $this->assertSame($payload, $handler->read('session-id'));
    }

    public function testInvalidCookieEnvelopesReturnEmptyString(): void
    {
        $handler = new CookieSessionHandler(m::mock(QueueingFactory::class), 120);

        foreach ([
            'garbage' => 'not serialized data',
            'top-level object' => serialize(new stdClass),
            'missing data' => serialize(['expires' => time() + 60]),
            'non-string data' => serialize(['data' => ['payload'], 'expires' => time() + 60]),
            'non-integer expiry' => serialize(['data' => 'payload', 'expires' => (string) (time() + 60)]),
            'expired' => serialize(['data' => 'payload', 'expires' => time() - 1]),
        ] as $description => $cookieValue) {
            $handler->setRequest(Request::create('/', cookies: ['session-id' => $cookieValue]));

            $this->assertSame('', $handler->read('session-id'), $description);
        }
    }
}
