<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\UrlSigningTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Http\Request;
use Hypervel\Routing\Exceptions\InvalidSignatureException;
use Hypervel\Routing\Middleware\ValidateSignature;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\URL;
use Hypervel\Support\Uri;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Integration\Routing\RoutingTestCase;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class UrlSigningTest extends RoutingTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set(['app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF']);
    }

    public function testSigningUrl(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1]));

        tap($this->get($url), function ($response) {
            $this->assertSame('valid', $response->original);

            $this->assertIsString($response->baseRequest->query('signature'));
        });
    }

    public function testSigningUrlRequiresPreviousKeysConfiguration(): void
    {
        $config = $this->app->make('config');
        $appConfig = $config->array('app');
        $appConfig['key'] = 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF';
        unset($appConfig['previous_keys']);

        $config->set('app', $appConfig);

        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        $this->expectExceptionObject(new InvalidArgumentException(
            'Configuration value for key [app.previous_keys] must be an array, NULL given.'
        ));

        URL::signedRoute('foo', ['id' => 1]);
    }

    public function testSigningUrlWithCustomRouteSlug(): void
    {
        Route::get('/foo/{post:slug}', function (Request $request, $slug) {
            return ['slug' => $slug, 'valid' => $request->hasValidSignature() ? 'valid' : 'invalid'];
        })->name('foo');

        $model = new RoutableInterfaceStub;
        $model->routable = 'routable-slug';

        $this->assertIsString($url = URL::signedRoute('foo', ['post' => $model]));

        tap($this->get($url), function ($response) {
            $this->assertSame('valid', $response->original['valid']);
            $this->assertSame('routable-slug', $response->original['slug']);

            $this->assertSame('routable-slug', $response->baseRequest->route('post'));
        });
    }

    public function testSigningUrlWithPercentSignInRouteSlug(): void
    {
        Route::get('/foo/{post:slug}', function (Request $request, string $slug): array {
            return ['slug' => $slug, 'valid' => $request->hasValidSignature() ? 'valid' : 'invalid'];
        })->name('foo');

        $model = new RoutableInterfaceStub;
        $model->slug = '%66oo';

        // The percent sign has to be escaped in the generated URL. Otherwise the router
        // decodes "%66" back into an "f" when matching the URL and binds a different
        // model than the one the URL was generated for...
        $this->assertSame(
            '/foo/%2566oo',
            parse_url($url = URL::signedRoute('foo', ['post' => $model]), PHP_URL_PATH)
        );

        tap($this->get($url), function (TestResponse $response): void {
            $this->assertSame('valid', $response->original['valid']);
            $this->assertSame('%66oo', $response->original['slug']);

            $this->assertSame('%66oo', $response->baseRequest->route('post'));
        });
    }

    public function testTemporarySignedUrls(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        CarbonImmutable::setTestNow(CarbonImmutable::create(2018, 1, 1));
        $this->assertIsString($url = URL::temporarySignedRoute('foo', now()->addMinutes(5), ['id' => 1]));
        $this->assertSame('valid', $this->get($url)->original);

        $uri = Uri::temporarySignedRoute('foo', now()->addMinutes(5), ['id' => 1]);

        $this->assertSame(now()->addMinutes(5)->getTimestamp(), $uri->query()->integer('expires'));
        $this->assertSame('valid', $this->get($uri->value())->original);

        CarbonImmutable::setTestNow(CarbonImmutable::create(2018, 1, 1)->addMinutes(10));
        $this->assertSame('invalid', $this->get($url)->original);
    }

    public function testTemporarySignedUrlsWithExpiresParameter(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('reserved'));

        Route::get('/foo/{id}', function (Request $request, string $id): string {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        URL::temporarySignedRoute('foo', now()->addMinutes(5), ['id' => 1, 'expires' => 253402300799]);
    }

    public function testSignedUrlWithUrlWithoutSignatureParameter(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertSame('invalid', $this->get('/foo/1')->original);
    }

    public function testSignedUrlWithNullParameter(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1, 'param']));
        $this->assertSame('valid', $this->get($url)->original);
    }

    public function testSignedUrlWithEmptyStringParameter(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1, 'param' => '']));
        $this->assertSame('valid', $this->get($url)->original);
    }

    public function testSignedUrlWithMultipleParameters(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1, 'param1' => 'value1', 'param2' => 'value2']));
        $this->assertSame('valid', $this->get($url)->original);
    }

    public function testSignedUrlWithSignatureTextInKeyOrValue(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1, 'custom-signature' => 'signature=value']));
        $this->assertSame('valid', $this->get($url)->original);
    }

    public function testSignedUrlWithAppendedNullParameterInvalid(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1]));
        $this->assertSame('invalid', $this->get($url . '&appended')->original);
    }

    // REMOVED: Vapor's query-string override and fallback tests. Swoole uses
    // QUERY_STRING directly, covered by testSigningUrl.

    public function testSignedUrlParametersParsedCorrectly(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature()
                && (int) $id === 1
                && $request->has('paramEmpty')
                && $request->has('paramEmptyString')
                && $request->query('paramWithValue') === 'value'
                ? 'valid'
                : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1,
            'paramEmpty',
            'paramEmptyString' => '',
            'paramWithValue' => 'value',
        ]));
        $this->assertSame('valid', $this->get($url)->original);
    }

    public function testExceptedParametersCanBeAddedInAnyOrder(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignatureWhileIgnoring(['one', 'two', 'three']) ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1,
            'bar' => 'baz',
        ]));

        $this->assertSame('valid', $this->get($url . '&one=value&two=another-value')->original);
        $this->assertSame('valid', $this->get($url . '&two=value&one=&three')->original);
    }

    public function testUnusualExceptedParametersWorksAsExpected(): void
    {
        $this->withoutExceptionHandling();
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignatureWhileIgnoring(['']) ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1,
            'bar' => 'baz',
        ]));

        $this->assertSame('valid', $this->get($url)->original);

        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignatureWhileIgnoring(['*', '[a-z]+']) ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1,
            'bar' => 'baz',
        ]));

        $this->assertSame('valid', $this->get($url . '&*=value&[a-z]+=value')->original);
    }

    public function testExceptedParameterCanBeAPrefixOrSuffixOfAnotherParameter(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignatureWhileIgnoring(['pre', 'fix']) ? 'valid' : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1,
            'prefix' => 'value',
            'suffix' => 'value',
        ]));

        $this->assertSame('valid', $this->get($url . '&pre=fix&fix=suff')->original);
    }

    public function testSignedMiddleware(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo')->middleware(ValidateSignature::class);

        CarbonImmutable::setTestNow(CarbonImmutable::create(2018, 1, 1));
        $this->assertIsString($url = URL::temporarySignedRoute('foo', now()->addMinutes(5), ['id' => 1]));
        $this->assertSame('valid', $this->get($url)->original);
    }

    public function testSignedMiddlewareWithInvalidUrl(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo')->middleware(ValidateSignature::class);

        CarbonImmutable::setTestNow(CarbonImmutable::create(2018, 1, 1));
        $this->assertIsString($url = URL::temporarySignedRoute('foo', now()->addMinutes(5), ['id' => 1]));
        CarbonImmutable::setTestNow(CarbonImmutable::create(2018, 1, 1)->addMinutes(10));

        $response = $this->get($url);
        $response->assertStatus(403);
    }

    public function testSignedMiddlewareWithRoutableParameter(): void
    {
        $model = new RoutableInterfaceStub;
        $model->routable = 'routable';

        Route::get('/foo/{bar}', function (Request $request, $routable) {
            return $request->hasValidSignature() ? $routable : 'invalid';
        })->name('foo');

        $this->assertIsString($url = URL::signedRoute('foo', $model));
        $this->assertSame('routable', $this->get($url)->original);
    }

    public function testSignedMiddlewareWithRelativePath(): void
    {
        Route::get('/foo/relative', function (Request $request) {
            return $request->hasValidSignature($absolute = false) ? 'valid' : 'invalid';
        })->name('foo')->middleware('signed:relative');

        $this->assertIsString($url = 'https://fake.test' . URL::signedRoute('foo', [], null, $absolute = false));
        $this->assertSame('valid', $this->get($url)->original);

        $response = $this->get('/foo/relative');
        $response->assertStatus(403);
    }

    public function testSignedMiddlewareIgnoringParameter(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
        })->name('foo')->middleware('signed:relative');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1]) . '&ignore=me');
        $request = Request::create($url);
        $middleware = $this->createValidateSignatureMiddleware(['ignore']);

        try {
            $middleware->handle($request, function ($request) {
                $this->assertTrue($request->hasValidSignatureWhileIgnoring(['ignore']));

                return new Response;
            });
        } catch (InvalidSignatureException $exception) {
            $this->fail($exception->getMessage());
        }
    }

    public function testSignedMiddlewareIgnoringParameterViaArgumentsWithRelative(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
        })->name('foo')->middleware('signed:relative,ignore');

        $this->assertIsString('https://fake.test' . URL::signedRoute('foo', ['id' => 1, 'ignore' => 'me'], null, false));

        $response = $this->get('/foo/1');
        $response->assertStatus(403);
    }

    public function testSignedMiddlewareCanGloballyIgnoreParameters(): void
    {
        ValidateSignature::except(['globally_ignore']);

        Route::get('/foo/{id}', function (Request $request, $id) {
        })->name('foo')->middleware('signed:relative');

        $this->assertIsString($url = URL::signedRoute('foo', ['id' => 1]) . '&globally_ignore=me');
        $request = Request::create($url);
        $middleware = $this->createValidateSignatureMiddleware(['ignore']);

        try {
            $middleware->handle($request, function ($request) {
                $this->assertTrue($request->hasValidSignatureWhileIgnoring(['globally_ignore']));

                return new Response;
            });
        } catch (InvalidSignatureException $exception) {
            $this->fail($exception->getMessage());
        }
    }

    public function testSignedMiddlewareIgnoringParameterViaArgumentsWithoutRelative(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
        })->name('foo')->middleware('signed:ignore');

        $this->assertIsString($url = 'https://fake.test' . URL::signedRoute('foo', ['id' => 1, 'ignore' => 'me'], null, false));

        $response = $this->get('/foo/1');
        $response->assertStatus(403);
    }

    public function testSignedMiddlewareIgnoringParameterViaClassAndArguments(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
        })->name('foo')->middleware(IgnoreParameterMiddleware::relative('test'));

        $this->assertIsString($url = 'https://fake.test' . URL::signedRoute('foo', ['id' => 1, 'ignore' => 'me', 'test' => 'bar'], null, false));

        $response = $this->get('/foo/1');
        $response->assertStatus(403);
    }

    public function testItCanGenerateMiddlewareDefinitionViaStaticMethod(): void
    {
        $signature = (string) ValidateSignature::relative();
        $this->assertSame('Hypervel\Routing\Middleware\ValidateSignature:relative', $signature);

        $signature = (string) ValidateSignature::absolute();
        $this->assertSame('Hypervel\Routing\Middleware\ValidateSignature', $signature);

        $signature = (string) ValidateSignature::relative(['foo', 'bar']);
        $this->assertSame('Hypervel\Routing\Middleware\ValidateSignature:relative,foo,bar', $signature);

        $signature = (string) ValidateSignature::absolute(['foo', 'bar']);
        $this->assertSame('Hypervel\Routing\Middleware\ValidateSignature:foo,bar', $signature);
    }

    public function testUrlsSignedByPreviousAppKeysAreValidWhenAddedAsPreviousKeys(): void
    {
        Route::get('/foo/{id}', function (Request $request, $id) {
            return $request->hasValidSignature() ? 'valid' : 'invalid';
        })->name('foo');

        config(['app.key' => 'oldest-key']);
        $oldestURL = URL::signedRoute('foo', ['id' => 1]);

        config(['app.key' => 'old-key']);
        $oldURL = URL::signedRoute('foo', ['id' => 1]);

        config(['app.key' => 'new-key']);
        $newUrl = URL::signedRoute('foo', ['id' => 1]);

        tap($this->get($oldestURL), fn ($response) => $this->assertSame('invalid', $response->original));
        tap($this->get($oldURL), fn ($response) => $this->assertSame('invalid', $response->original));
        tap($this->get($newUrl), fn ($response) => $this->assertSame('valid', $response->original));

        config(['app.previous_keys' => ['old-key', 'oldest-key']]);

        tap($this->get($oldestURL), fn ($response) => $this->assertSame('valid', $response->original));
        tap($this->get($oldURL), fn ($response) => $this->assertSame('valid', $response->original));
        tap($this->get($newUrl), fn ($response) => $this->assertSame('valid', $response->original));
    }

    protected function createValidateSignatureMiddleware(array $ignore)
    {
        return new class($ignore) extends ValidateSignature {
            public function __construct(array $ignore)
            {
                $this->ignore = $ignore;
            }
        };
    }
}

class RoutableInterfaceStub implements UrlRoutable
{
    public mixed $key = null;

    public mixed $routable = null;

    public string $slug = 'routable-slug';

    public function getRouteKey(): mixed
    {
        return $this->{$this->getRouteKeyName()};
    }

    public function getRouteKeyName(): string
    {
        return 'routable';
    }

    public function resolveRouteBinding(mixed $routeKey, ?string $field = null): ?static
    {
        return null;
    }

    public function resolveChildRouteBinding(string $childType, mixed $routeKey, ?string $field = null): ?static
    {
        return null;
    }
}

class IgnoreParameterMiddleware extends ValidateSignature
{
    protected array $ignore = ['ignore'];
}
