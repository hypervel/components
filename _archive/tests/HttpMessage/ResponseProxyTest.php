<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage;

use Hypervel\HttpMessage\Server\Response;
use Hypervel\Tests\HttpMessage\Stub\Server\ResponseStub;

/**
 * @internal
 * @coversNothing
 */
class ResponseProxyTest extends ResponseTest
{
    public function testStatusCode()
    {
        parent::testStatusCode();
    }

    public function testHeaders()
    {
        parent::testHeaders();
    }

    public function testCookies()
    {
        parent::testCookies();
    }

    public function testWrite()
    {
        $this->markTestSkipped('Response proxy does not support chunk.');
    }

    protected function newResponse()
    {
        $response = new ResponseStub();
        $response->setResponse(new Response());
        return $response;
    }
}
