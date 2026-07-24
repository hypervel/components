<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Security;

class SecurityTest extends TestCase
{
    public function testValidatesAndSignsWebSocketKeys(): void
    {
        $security = new Security;
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';

        $this->assertFalse($security->isInvalidSecurityKey($key));
        $this->assertTrue($security->isInvalidSecurityKey('invalid'));
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $security->sign($key));
    }

    public function testHandshakeHeadersUseCanonicalNames(): void
    {
        $headers = (new Security)->handshakeHeaders('dGhlIHNhbXBsZSBub25jZQ==');

        $this->assertSame('#^[+/0-9A-Za-z]{21}[AQgw]==$#', Security::PATTERN);
        $this->assertSame('sec-websocket-protocol', Security::SEC_WEBSOCKET_PROTOCOL);
        $this->assertSame('websocket', $headers['Upgrade']);
        $this->assertSame('Upgrade', $headers['Connection']);
        $this->assertSame('13', $headers['Sec-WebSocket-Version']);
    }
}
