<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Http\Http;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HttpTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testHttpPackRequest()
    {
        $data = Http::packRequest('GET', '/', ['Content-Type' => 'application/json'], 'Hello World');

        $this->assertSame("GET / HTTP/1.1\r\nContent-Type: application/json\r\n\r\nHello World", $data);
    }

    public function testHttpPackResponse()
    {
        $data = Http::packResponse(200, 'OK', ['Content-Type' => 'application/json'], 'Hello World');

        $this->assertSame("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\nHello World", $data);
    }
}
