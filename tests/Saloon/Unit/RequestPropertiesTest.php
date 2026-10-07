<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Saloon\Http\MiddlewarePipeline;
use Hypervel\Tests\Saloon\Fixtures\Requests\DefaultPropertiesRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\TestCase;

class RequestPropertiesTest extends TestCase
{
    public function testYouCanRetrieveAllTheRequestParametersMethods(): void
    {
        $request = new UserRequest;

        // Request properties are read as arrays and changed through fluent methods instead of upstream's stores.
        $this->assertSame([], $request->headers());
        $this->assertSame([], $request->queryParameters());
        $this->assertSame([], $request->options());
        $this->assertInstanceOf(MiddlewarePipeline::class, $request->middleware());
    }

    public function testAllOfTheRequestPropertiesCanHaveDefaultProperties(): void
    {
        $request = new DefaultPropertiesRequest;

        $this->assertSame(['X-Favourite-Artist' => 'Luke Combs'], $request->headers());
        $this->assertSame(['format' => 'json'], $request->queryParameters());
        $this->assertSame(['debug' => true], $request->options());
    }
}
