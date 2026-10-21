<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Facades;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Support\Facades\Request;
use Hypervel\Testbench\TestCase;

class RequestTest extends TestCase
{
    public function testResolvesTheCurrentRequestAfterContextReplacement(): void
    {
        RequestContext::set(HttpRequest::create('/?state=first'));

        $this->assertSame('first', Request::query('state'));

        RequestContext::set(HttpRequest::create('/?state=second'));

        $this->assertSame('second', Request::query('state'));
    }
}
