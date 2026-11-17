<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Contracts\Cookie\QueueingFactory;
use Hypervel\Http\Request;
use Hypervel\Session\CookieSessionHandler;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use stdClass;

class CookieSessionHandlerTest extends TestCase
{
    use VerifiesDoubles;

    public function testBinarySessionPayloadRoundTripsThroughCookieEnvelope(): void
    {
        $cookie = Double::for(QueueingFactory::class)->strict();
        $handler = new CookieSessionHandler($cookie, 120);
        $payload = serialize(['binary' => "\x00\xff\x10"]);
        $cookieValue = null;

        $cookie->expects('queue')
            ->with('session-id', Argument::capture($cookieValue), 120);

        $this->assertTrue($handler->write('session-id', $payload));
        $this->assertIsString($cookieValue);

        $handler->setRequest(Request::create('/', cookies: ['session-id' => $cookieValue]));

        $this->assertSame($payload, $handler->read('session-id'));
    }

    public function testInvalidCookieEnvelopesReturnEmptyString(): void
    {
        $handler = new CookieSessionHandler(Double::for(QueueingFactory::class)->strict(), 120);

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
