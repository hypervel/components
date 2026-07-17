<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Http\V2\Client;
use Hypervel\Tests\TestCase;

class Http2ClientTest extends TestCase
{
    public function testConstructionRejectsAFailedConnection(): void
    {
        $this->expectException(HttpClientException::class);

        new Client('127.0.0.1', 0);
    }
}
