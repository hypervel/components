<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\Response;
use Hypervel\Tests\TestCase;

class HttpResponseTest extends TestCase
{
    public function testConstructorInitializesStatusHeadersContentAndOriginalContent(): void
    {
        $response = new Response(['name' => 'Taylor'], 201, ['X-Test' => 'yes']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('yes', $response->headers->get('X-Test'));
        $this->assertSame('1.0', $response->getProtocolVersion());
        $this->assertSame('{"name":"Taylor"}', $response->getContent());
        $this->assertSame(['name' => 'Taylor'], $response->getOriginalContent());
    }
}
