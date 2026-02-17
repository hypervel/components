<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage;

use Hypervel\HttpMessage\Base\Request;
use Hypervel\HttpMessage\Server\Request as ServerRequest;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MessageTraitTest extends TestCase
{
    public function testSetHeaders()
    {
        $token = uniqid();
        $id = rand(1000, 9999);
        $request = new Request('GET', '/', [
            'X-Token' => $token,
            'X-Id' => $id,
            'Version' => 1.0,
            1000 => 1000,
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertSame($token, $request->getHeaderLine('X-Token'));
        $this->assertSame((string) $id, $request->getHeaderLine('X-Id'));
        $this->assertSame('1', $request->getHeaderLine('Version'));
        $this->assertSame('1000', $request->getHeaderLine('1000'));
        $this->assertSame('XMLHttpRequest', $request->getHeaderLine('X-Requested-With'));
    }

    public function testIsXmlHttpRequest()
    {
        $request = new ServerRequest('GET', '/', [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertTrue($request->isXmlHttpRequest());
    }
}
