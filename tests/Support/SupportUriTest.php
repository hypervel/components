<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use BackedEnum;
use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Support\Stringable as HypervelStringable;
use Hypervel\Support\Uri;
use Hypervel\Tests\TestCase;
use League\Uri\Uri as LeagueUri;
use Mockery as m;
use stdClass;
use Stringable;
use TypeError;

class SupportUriTest extends TestCase
{
    public function testCanBuildSpecialUrls(): void
    {
        Uri::setUrlGeneratorResolver(fn () => new CustomUrlGeneratorResolver);

        $this->assertSame('https://hypervel.org/to', Uri::to('')->value());
        $this->assertSame('https://hypervel.org/route', Uri::route('')->value());
        $this->assertSame('https://hypervel.org/signed-route', Uri::signedRoute('')->value());
        $this->assertSame('https://hypervel.org/signed-route', Uri::temporarySignedRoute('', 60)->value());
        $this->assertSame('https://hypervel.org/action', Uri::action('')->value());
    }

    public function testSpecialUrlParametersAcceptUrlRoutableInstances(): void
    {
        Uri::setUrlGeneratorResolver(fn () => new CustomUrlGeneratorResolver);

        $parameter = m::mock(UrlRoutable::class);

        $this->assertSame('https://hypervel.org/route', Uri::route('', $parameter)->value());
        $this->assertSame('https://hypervel.org/signed-route', Uri::signedRoute('', $parameter)->value());
        $this->assertSame('https://hypervel.org/signed-route', Uri::temporarySignedRoute('', 60, $parameter)->value());
        $this->assertSame('https://hypervel.org/action', Uri::action('', $parameter)->value());
    }

    public function testBasicUriInteractions(): void
    {
        $uri = Uri::of($originalUri = 'https://hypervel.org/docs/installation');

        $this->assertSame('https', $uri->scheme());
        $this->assertNull($uri->user());
        $this->assertNull($uri->password());
        $this->assertSame('hypervel.org', $uri->host());
        $this->assertNull($uri->port());
        $this->assertSame('docs/installation', $uri->path());
        $this->assertSame([], $uri->query()->toArray());
        $this->assertSame('', (string) $uri->query());
        $this->assertSame('', $uri->query()->decode());
        $this->assertNull($uri->fragment());
        $this->assertEquals($originalUri, (string) $uri);

        $uri = Uri::of('https://taylor:password@hypervel.org/docs/installation?version=1#hello');

        $this->assertSame('taylor', $uri->user());
        $this->assertSame('password', $uri->password());
        $this->assertSame('hello', $uri->fragment());
        $this->assertEquals(['version' => 1], $uri->query()->all());
        $this->assertEquals(1, $uri->query()->integer('version'));
        $this->assertSame('taylor:password@hypervel.org', $uri->authority());
    }

    public function testIsEmptyAndIsNotEmpty(): void
    {
        $this->assertTrue(Uri::of('')->isEmpty());
        $this->assertFalse(Uri::of('')->isNotEmpty());

        $this->assertFalse(Uri::of('https://hypervel.org')->isEmpty());
        $this->assertTrue(Uri::of('https://hypervel.org')->isNotEmpty());
    }

    public function testWithoutFragment(): void
    {
        $uri = Uri::of('https://hypervel.org/docs/installation#introduction');

        $this->assertSame('introduction', $uri->fragment());

        $withoutFragment = $uri->withoutFragment();

        $this->assertNull($withoutFragment->fragment());
        $this->assertSame('https://hypervel.org/docs/installation', $withoutFragment->value());

        // Original URI should be unchanged (immutability).
        $this->assertSame('introduction', $uri->fragment());
    }

    public function testWithoutFragmentOnUriWithoutFragment(): void
    {
        $uri = Uri::of('https://hypervel.org/docs');

        $withoutFragment = $uri->withoutFragment();

        $this->assertNull($withoutFragment->fragment());
        $this->assertSame('https://hypervel.org/docs', $withoutFragment->value());
    }

    public function testQueryAllSelectsZeroKeys(): void
    {
        $query = Uri::of('https://hypervel.org/?0=first&name=Taylor')->query();

        $this->assertSame([0 => 'first'], $query->all(0));
        $this->assertSame($query->all([0]), $query->all('0'));
        $this->assertSame([0 => 'first', 'missing' => null], $query->all('0', 'missing'));
        $this->assertSame([], $query->all([]));
    }

    public function testComplicatedQueryStringParsing(): void
    {
        $uri = Uri::of('https://example.com/users?key_1=value&key_2[sub_field]=value&key_3[]=value&key_4[9]=value&key_5[][][foo][9]=bar&key.6=value&flag_value');

        $this->assertEquals([
            'key_1' => 'value',
            'key_2' => [
                'sub_field' => 'value',
            ],
            'key_3' => [
                'value',
            ],
            'key_4' => [
                9 => 'value',
            ],
            'key_5' => [
                [
                    [
                        'foo' => [
                            9 => 'bar',
                        ],
                    ],
                ],
            ],
            'key.6' => 'value',
            'flag_value' => '',
        ], $uri->query()->all());

        $this->assertSame('key_1=value&key_2[sub_field]=value&key_3[]=value&key_4[9]=value&key_5[][][foo][9]=bar&key.6=value&flag_value', $uri->query()->decode());
    }

    public function testUriBuilding(): void
    {
        $uri = Uri::of();

        $uri = $uri->withHost('hypervel.org')
            ->withScheme('https')
            ->withUser('taylor', 'password')
            ->withPath('/docs/installation')
            ->withPort(80)
            ->withQuery(['version' => 1])
            ->withFragment('hello');

        $expected = 'https://taylor:password@hypervel.org:80/docs/installation?version=1#hello';

        $this->assertEquals($expected, (string) $uri);
        $this->assertEquals($expected, $uri->value());
        $this->assertEquals($expected, $uri->toString());
    }

    public function testToStringable(): void
    {
        $uri = Uri::of('https://taylor:password@hypervel.org:80/docs/installation?version=1#hello');

        $stringable = $uri->toStringable();

        $this->assertInstanceOf(HypervelStringable::class, $stringable);
        $this->assertSame('https://taylor:password@hypervel.org:80/docs/installation?version=1#hello', (string) $stringable);
        $this->assertSame($uri->value(), (string) $stringable);
    }

    public function testComplicatedQueryStringManipulation(): void
    {
        $uri = Uri::of('https://hypervel.org');

        $uri = $uri->withQuery([
            'name' => 'Taylor',
            'age' => 38,
            'role' => [
                'title' => 'Developer',
                'focus' => 'PHP',
            ],
            'tags' => [
                'person',
                'employee',
            ],
            'flag' => '',
        ])->withoutQuery(['name']);

        $this->assertSame('age=38&role[title]=Developer&role[focus]=PHP&tags[0]=person&tags[1]=employee&flag=', $uri->query()->decode());
        $this->assertSame('name=Taylor', $uri->replaceQuery(['name' => 'Taylor'])->query()->decode());

        // Push onto multi-value and missing items...
        $uri = Uri::of('https://hypervel.org?tags[]=foo');

        $this->assertEquals(['tags' => ['foo', 'bar']], $uri->pushOntoQuery('tags', 'bar')->query()->all());
        $this->assertEquals(['tags' => ['foo', 'bar', 'baz']], $uri->pushOntoQuery('tags', ['bar', 'baz'])->query()->all());
        $this->assertEquals(['tags' => ['foo'], 'names' => ['Taylor']], $uri->pushOntoQuery('names', 'Taylor')->query()->all());

        // Push onto single value item...
        $uri = Uri::of('https://hypervel.org?tag=foo');

        $this->assertEquals(['tag' => ['foo', 'bar']], $uri->pushOntoQuery('tag', 'bar')->query()->all());
    }

    public function testQueryStringsWithDotsCanBeReplacedOrMergedConsistently(): void
    {
        $uri = Uri::of('https://dot.test/?foo.bar=baz');

        $this->assertSame('foo.bar=baz&foo[bar]=zab', $uri->withQuery(['foo.bar' => 'zab'])->query()->decode());
        $this->assertSame('foo[bar]=zab', $uri->replaceQuery(['foo.bar' => 'zab'])->query()->decode());
    }

    public function testDecodingTheEntireUri(): void
    {
        $uri = Uri::of('https://hypervel.org/docs/11.x/installation')->withQuery(['tags' => ['first', 'second']]);

        $this->assertSame('https://hypervel.org/docs/11.x/installation?tags[0]=first&tags[1]=second', $uri->decode());
    }

    public function testDecodingTheEntireUriPreservesTheFragment(): void
    {
        $uri = Uri::of('https://hypervel.org/docs/11.x/routing?q=hypervel%20docs#route-model-binding');

        $this->assertSame('https://hypervel.org/docs/11.x/routing?q=hypervel docs#route-model-binding', $uri->decode());
    }

    public function testWithQueryIfMissing(): void
    {
        // Test adding new parameters while preserving existing ones
        $uri = Uri::of('https://hypervel.org?existing=value');

        $uri = $uri->withQueryIfMissing([
            'new' => 'parameter',
            'existing' => 'new_value',
        ]);

        $this->assertSame('existing=value&new=parameter', $uri->query()->decode());

        // Test adding complex nested arrays to empty query string
        $uri = Uri::of('https://hypervel.org');

        $uri = $uri->withQueryIfMissing([
            'name' => 'Taylor',
            'role' => [
                'title' => 'Developer',
                'focus' => 'PHP',
            ],
            'tags' => [
                'person',
                'employee',
            ],
        ]);

        $this->assertSame('name=Taylor&role[title]=Developer&role[focus]=PHP&tags[0]=person&tags[1]=employee', $uri->query()->decode());

        // Test partial array merging and preserving indexed arrays
        $uri = Uri::of('https://hypervel.org?name=Taylor&tags[0]=person');

        $uri = $uri->withQueryIfMissing([
            'name' => 'Changed',
            'age' => 38,
            'tags' => ['should', 'not', 'change'],
        ]);

        $this->assertSame('name=Taylor&tags[0]=person&age=38', $uri->query()->decode());
        $this->assertEquals(['name' => 'Taylor', 'tags' => ['person'], 'age' => 38], $uri->query()->all());

        $uri = Uri::of('https://hypervel.org?user[name]=Taylor');

        $uri = $uri->withQueryIfMissing([
            'user' => [
                'name' => 'Should Not Change',
                'age' => 38,
            ],
            'settings' => [
                'theme' => 'dark',
            ],
        ]);
        $this->assertEquals([
            'user' => [
                'name' => 'Taylor',
            ],
            'settings' => [
                'theme' => 'dark',
            ],
        ], $uri->query()->all());
    }

    public function testWithQueryPreventsEmptyQueryString(): void
    {
        $uri = Uri::of('https://hypervel.org');

        $this->assertSame('https://hypervel.org', (string) $uri);
        $this->assertSame('https://hypervel.org', (string) $uri->withQuery([]));
    }

    public function testUriHelperCastsStringableInputsExactlyOnce(): void
    {
        $stringable = new CountingUriStringable('/docs');

        $result = uri($stringable);

        $this->assertSame('/docs', $result->value());
        $this->assertSame(1, $stringable->casts);
        $this->assertSame(
            '/league',
            uri(LeagueUri::new('/league'))->value(),
        );
    }

    public function testUriHelperPreservesArrayActions(): void
    {
        $resolver = new CustomUrlGeneratorResolver;
        Uri::setUrlGeneratorResolver(fn () => $resolver);
        $action = ['App\Http\Controllers\UserController', 'index'];

        $this->assertSame('https://hypervel.org/action', uri($action)->value());
        $this->assertSame($action, $resolver->lastAction);
    }

    public function testUriHelperRoutesControllerStringsAfterCasting(): void
    {
        $resolver = new CustomUrlGeneratorResolver;
        Uri::setUrlGeneratorResolver(fn () => $resolver);
        $action = new CountingUriStringable('App\Http\Controllers\UserController@index');

        $this->assertSame('https://hypervel.org/action', uri($action)->value());
        $this->assertSame('App\Http\Controllers\UserController@index', $resolver->lastAction);
        $this->assertSame(1, $action->casts);
    }

    public function testUriHelperRejectsUnsupportedObjectsAtItsTypeBoundary(): void
    {
        try {
            uri(new stdClass);
            $this->fail('Expected an unsupported URI value to be rejected.');
        } catch (TypeError $exception) {
            $this->assertStringContainsString('uri(): Argument #1 ($uri)', $exception->getMessage());
        }
    }

    public function testPathSegments(): void
    {
        $uri = Uri::of('https://hypervel.org');

        $this->assertSame([], $uri->pathSegments()->toArray());

        $uri = Uri::of('https://hypervel.org/one/two/three');

        $this->assertEquals(['one', 'two', 'three'], $uri->pathSegments()->toArray());
        $this->assertSame('one', $uri->pathSegments()->first());

        $uri = Uri::of('https://hypervel.org/one/two/three?foo=bar');

        $this->assertCount(3, $uri->pathSegments());

        $uri = Uri::of('https://hypervel.org/one/two/three/?foo=bar');

        $this->assertCount(3, $uri->pathSegments());

        $uri = Uri::of('https://hypervel.org/one/two/three/#foo=bar');

        $this->assertCount(3, $uri->pathSegments());
    }

    public function testMacroable(): void
    {
        Uri::macro('myMacro', function () {
            return $this->withPath('foobar');
        });

        $uri = new Uri('https://hypervel.org/');

        $this->assertSame('https://hypervel.org/foobar', (string) $uri->myMacro());
    }
}

class CustomUrlGeneratorResolver implements UrlGenerator
{
    public array|string|null $lastAction = null;

    public function current(): string
    {
        return 'https://hypervel.org/current';
    }

    public function previous(bool|string $fallback = false): string
    {
        return 'https://hypervel.org/previous';
    }

    public function to(string $path, mixed $extra = [], ?bool $secure = null): string
    {
        return 'https://hypervel.org/to';
    }

    public function secure(string $path, mixed $parameters = []): string
    {
        return 'https://hypervel.org/secure';
    }

    public function asset(string $path, ?bool $secure = null): string
    {
        return 'https://hypervel.org/asset';
    }

    public function route(BackedEnum|string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return 'https://hypervel.org/route';
    }

    public function signedRoute(BackedEnum|string $name, mixed $parameters = [], DateInterval|DateTimeInterface|int|null $expiration = null, bool $absolute = true): string
    {
        return 'https://hypervel.org/signed-route';
    }

    public function temporarySignedRoute(BackedEnum|string $name, DateInterval|DateTimeInterface|int $expiration, mixed $parameters = [], bool $absolute = true): string
    {
        return 'https://hypervel.org/temporary-signed-route';
    }

    public function query(string $path, array $query = [], mixed $extra = [], ?bool $secure = null): string
    {
        return 'https://hypervel.org/query';
    }

    public function action(array|string $action, mixed $parameters = [], bool $absolute = true): string
    {
        $this->lastAction = $action;

        return 'https://hypervel.org/action';
    }

    public function getRootControllerNamespace(): ?string
    {
        return 'App\Http\Controllers';
    }

    public function setRootControllerNamespace(string $rootNamespace): static
    {
        return $this;
    }
}

class CountingUriStringable implements Stringable
{
    public int $casts = 0;

    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        ++$this->casts;

        return $this->value;
    }
}
