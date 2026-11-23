<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\Middleware\TransformsRequestTest;

use Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Hypervel\Foundation\Http\Middleware\TransformsRequest;
use Hypervel\Foundation\Http\Middleware\TrimStrings;
use Hypervel\Http\Exceptions\PostTooLargeException;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

use function Hypervel\Coroutine\parallel;

class TransformsRequestTest extends TestCase
{
    #[DataProvider('middlewareProvider')]
    public function testUnchangedNestedArraysAreNotCopied(string $middlewareClass): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[' . str_repeat('[0],', 19999) . '[0]]');
        $original = $request->json()->all();
        $middleware = new $middlewareClass;
        $before = memory_get_usage();

        $middleware->handle($request, fn () => null);

        // Copying the 20,000 unchanged inner arrays would require several MiB.
        $this->assertLessThan(1024 * 1024, memory_get_usage() - $before);
        $this->assertSame($original, $request->json()->all());
    }

    /**
     * Provide middleware that transforms request input.
     */
    public static function middlewareProvider(): iterable
    {
        yield [TransformsRequest::class];
        yield [TrimStrings::class];
        yield [ConvertEmptyStringsToNull::class];
    }

    #[DataProvider('amplifiedInputProvider')]
    public function testRejectsAmplifiedJsonKeys(string $middlewareClass, mixed $value): void
    {
        $key = str_repeat('è', 197499);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            $key => array_fill(0, 100, $value),
        ], JSON_UNESCAPED_UNICODE));

        try {
            (new $middlewareClass)->handle($request, function (): never {
                $this->fail('An amplified request should not reach the next middleware.');
            });

            $this->fail('An amplified request should be rejected.');
        } catch (PostTooLargeException $exception) {
            $this->assertSame(413, $exception->getStatusCode());
        }
    }

    /**
     * Provide middleware and values that amplify nested input keys.
     */
    public static function amplifiedInputProvider(): iterable
    {
        foreach ([TransformsRequest::class, TrimStrings::class, ConvertEmptyStringsToNull::class] as $middleware) {
            foreach ([0, ' value ', '', []] as $value) {
                yield [$middleware, $value];
            }
        }
    }

    public function testKeyBudgetIncludesNestedPathsAndPreservesDottedKeys(): void
    {
        $middleware = new class extends TransformsRequest {
            protected int $maxKeyBytes = 10;

            /**
             * Return the full input path for the value.
             */
            protected function transform(string $key, mixed $value): mixed
            {
                return $key;
            }
        };

        // The processed keys are "foo" (3 bytes) and "foo.bar" (7 bytes).
        $middleware->handle(new Request(['foo' => ['bar' => 'value']]), function (Request $request): void {
            $this->assertSame('foo.bar', $request->input('foo.bar'));
        });

        $this->expectException(PostTooLargeException::class);

        $middleware->handle(new Request(['foo' => ['bars' => 'value']]), fn () => null);
    }

    public function testKeyBudgetIsSharedAcrossParameterBags(): void
    {
        $middleware = new class extends TransformsRequest {
            protected int $maxKeyBytes = 10;
        };

        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"foobar": 0}');
        $request->query->set('hello', 0);

        $this->expectException(PostTooLargeException::class);

        $middleware->handle($request, fn () => null);
    }

    public function testKeyBudgetIsSharedAcrossQueryAndFormParameters(): void
    {
        $middleware = new class extends TransformsRequest {
            protected int $maxKeyBytes = 10;
        };

        $request = Request::create('/?hello=0', 'POST', ['foobar' => 0]);

        $this->expectException(PostTooLargeException::class);

        $middleware->handle($request, fn () => null);
    }

    public function testKeyBudgetResetsWhenMiddlewareIsReused(): void
    {
        $middleware = new class extends TransformsRequest {
            protected int $maxKeyBytes = 10;
        };

        for ($i = 0; $i < 2; ++$i) {
            $middleware->handle(new Request(['1234567890' => 'value']), function (Request $request): void {
                $this->assertSame('value', $request->input('1234567890'));
            });
        }

        try {
            $middleware->handle(new Request(['12345678901' => 'value']), fn () => null);

            $this->fail('The key budget should be exceeded.');
        } catch (PostTooLargeException $exception) {
            $this->assertSame(413, $exception->getStatusCode());
        }

        $middleware->handle(new Request(['1234567890' => 'value']), function (Request $request): void {
            $this->assertSame('value', $request->input('1234567890'));
        });
    }

    public function testYieldingTransformsKeepEachRequestsKeyBudget(): void
    {
        $middleware = new class extends TransformsRequest {
            protected int $maxKeyBytes = 10;

            /**
             * Yield during transformation to interleave requests.
             */
            protected function transform(string $key, mixed $value): mixed
            {
                usleep(5000);

                return $value;
            }
        };
        $handle = static function (array $input) use ($middleware): int {
            try {
                return $middleware->handle(new Request($input), static fn (): int => 200);
            } catch (PostTooLargeException $exception) {
                return $exception->getStatusCode();
            }
        };

        $this->assertSame([200, 200], parallel([
            static fn (): int => $handle(['a' => ['b' => 1], 'c' => ['d' => 2]]),
            static fn (): int => $handle(['a' => ['b' => 1], 'c' => ['d' => 2]]),
        ]));

        $this->assertSame([413, 200], parallel([
            static fn (): int => $handle(['a' => ['bbbb' => 1], 'c' => ['dddd' => 2]]),
            static fn (): int => $handle([]),
        ]));
    }

    public function testEmptyParameterBagIsNotReadOrReplaced(): void
    {
        $bag = new TrackingParameterBag;

        (new ExposedTransformsRequest)->cleanBag($bag);

        $this->assertSame(0, $bag->allCalls);
        $this->assertSame(0, $bag->replaceCalls);
    }

    public function testNonEmptyParameterBagIsStillReadAndReplaced(): void
    {
        $bag = new TrackingParameterBag(['name' => 'Taylor']);

        (new ExposedTransformsRequest)->cleanBag($bag);

        $this->assertSame(1, $bag->allCalls);
        $this->assertSame(1, $bag->replaceCalls);
        $this->assertSame(['name' => 'Taylor'], $bag->all());
    }

    public function testTransformOncePerKeyWhenMethodIsGet(): void
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

    public function testTransformOncePerKeyWhenMethodIsPost(): void
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

    public function testTransformOncePerArrayKeysWhenMethodIsPost(): void
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

    public function testTransformOncePerKeyWhenContentTypeIsJson(): void
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
