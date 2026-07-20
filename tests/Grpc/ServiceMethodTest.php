<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Protocol\ServiceMethod;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ServiceMethodTest extends TestCase
{
    public function testParsesCanonicalAndUnprefixedMethods(): void
    {
        $canonical = ServiceMethod::parse('/helloworld.v1.Greeter/SayHello');
        $unprefixed = ServiceMethod::parse('Greeter/SayHello');

        $this->assertSame('helloworld.v1.Greeter', $canonical->service);
        $this->assertSame('SayHello', $canonical->method);
        $this->assertSame('/helloworld.v1.Greeter/SayHello', $canonical->path());
        $this->assertSame('Greeter', $unprefixed->service);
        $this->assertSame('/Greeter/SayHello', $unprefixed->path());
    }

    public function testCreatesMethodFromIndependentPartsAndPreservesCase(): void
    {
        $method = ServiceMethod::from('Example.API_Service', 'GetHTTPStatus');

        $this->assertSame('Example.API_Service', $method->service);
        $this->assertSame('GetHTTPStatus', $method->method);
        $this->assertSame('/Example.API_Service/GetHTTPStatus', $method->path());
    }

    public function testValidatesAServiceNameWithoutInventingAMethod(): void
    {
        ServiceMethod::validateServiceName('Example.API_Service');

        $this->addToAssertionCount(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The gRPC service name is invalid.');

        ServiceMethod::validateServiceName('Example..API');
    }

    public function testRejectsInvalidMethodPaths(): void
    {
        foreach ([
            '',
            '/Greeter',
            'Greeter/',
            '//Greeter/SayHello',
            '/Greeter/SayHello/Extra',
            '/Greeter/{method}',
            '/Greeter/SayHello?name=value',
            '/Greeter/SayHello#fragment',
        ] as $method) {
            try {
                ServiceMethod::parse($method);
                $this->fail("Expected gRPC method [{$method}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsInvalidServiceNames(): void
    {
        foreach (['', '.Greeter', 'Greeter.', 'example..Greeter', '1Greeter', 'example.Greet-er', "Greeter\n"] as $service) {
            try {
                ServiceMethod::from($service, 'SayHello');
                $this->fail("Expected gRPC service [{$service}] to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The gRPC service name is invalid.', $exception->getMessage());
            }
        }
    }

    public function testRejectsInvalidMethodNames(): void
    {
        foreach (['', '1SayHello', 'Say.Hello', 'Say-Hello', 'Say Hello', "SayHello\n"] as $method) {
            try {
                ServiceMethod::from('Greeter', $method);
                $this->fail("Expected gRPC method [{$method}] to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The gRPC method name is invalid.', $exception->getMessage());
            }
        }
    }
}
