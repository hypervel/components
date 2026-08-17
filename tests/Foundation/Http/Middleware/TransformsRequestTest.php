<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\Middleware\TransformsRequestTest;

use Hypervel\Foundation\Http\Middleware\TransformsRequest;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class TransformsRequestTest extends TestCase
{
    public function testEmptyParameterBagIsNotReadOrReplaced()
    {
        $bag = new TrackingParameterBag;

        (new ExposedTransformsRequest)->cleanBag($bag);

        $this->assertSame(0, $bag->allCalls);
        $this->assertSame(0, $bag->replaceCalls);
    }

    public function testNonEmptyParameterBagIsStillReadAndReplaced()
    {
        $bag = new TrackingParameterBag(['name' => 'Taylor']);

        (new ExposedTransformsRequest)->cleanBag($bag);

        $this->assertSame(1, $bag->allCalls);
        $this->assertSame(1, $bag->replaceCalls);
        $this->assertSame(['name' => 'Taylor'], $bag->all());
    }

    public function testTransformOncePerKeyWhenMethodIsGet()
    {
        $middleware = new TruncateInput;
        $symfonyRequest = new SymfonyRequest([
            'bar' => '123',
            'baz' => 'abc',
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame('12', $request->input('bar'));
            $this->assertSame('ab', $request->input('baz'));
        });
    }

    public function testTransformOncePerKeyWhenMethodIsPost()
    {
        $middleware = new ManipulateInput;
        $symfonyRequest = new SymfonyRequest(
            [
                'name' => 'Damian',
                'beers' => 4,
            ],
            ['age' => 28]
        );
        $symfonyRequest->server->set('REQUEST_METHOD', 'POST');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame('Damian', $request->input('name'));
            $this->assertEquals(27, $request->input('age'));
            $this->assertEquals(5, $request->input('beers'));
        });
    }

    public function testTransformOncePerArrayKeysWhenMethodIsPost()
    {
        $middleware = new ManipulateArrayInput;
        $symfonyRequest = new SymfonyRequest(
            [
                'name' => 'Damian',
                'beers' => [4, 8, 12],
            ],
            [
                'age' => [28, 56, 84],
            ]
        );
        $symfonyRequest->server->set('REQUEST_METHOD', 'POST');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame('Damian', $request->input('name'));
            $this->assertEquals([27, 55, 83], $request->input('age'));
            $this->assertEquals([5, 9, 13], $request->input('beers'));
        });
    }

    public function testTransformOncePerKeyWhenContentTypeIsJson()
    {
        $middleware = new ManipulateInput;
        $symfonyRequest = new SymfonyRequest(
            [
                'name' => 'Damian',
                'beers' => 4,
            ],
            [],
            [],
            [],
            [],
            ['CONTENT_TYPE' => '/json'],
            json_encode(['age' => 28])
        );
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $middleware->handle($request, function (Request $request) {
            $this->assertSame('Damian', $request->input('name'));
            $this->assertEquals(27, $request->input('age'));
            $this->assertEquals(5, $request->input('beers'));
        });
    }
}

class ManipulateInput extends TransformsRequest
{
    protected function transform(string $key, mixed $value): mixed
    {
        if ($key === 'beers') {
            ++$value;
        }

        if ($key === 'age') {
            --$value;
        }

        return $value;
    }
}

class ManipulateArrayInput extends TransformsRequest
{
    protected function transform(string $key, mixed $value): mixed
    {
        if (str_contains($key, 'beers')) {
            ++$value;
        }

        if (str_contains($key, 'age')) {
            --$value;
        }

        return $value;
    }
}

class TruncateInput extends TransformsRequest
{
    protected function transform(string $key, mixed $value): mixed
    {
        return substr($value, 0, -1);
    }
}

class ExposedTransformsRequest extends TransformsRequest
{
    public function cleanBag(ParameterBag $bag): void
    {
        $this->cleanParameterBag($bag);
    }
}

class TrackingParameterBag extends ParameterBag
{
    public int $allCalls = 0;

    public int $replaceCalls = 0;

    public function all(?string $key = null): array
    {
        ++$this->allCalls;

        return parent::all($key);
    }

    public function replace(array $parameters = []): void
    {
        ++$this->replaceCalls;

        parent::replace($parameters);
    }
}
