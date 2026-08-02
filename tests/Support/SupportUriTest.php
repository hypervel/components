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
use Mockery as m;

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

        $this->assertEquals('https', $uri->scheme());
        $this->assertNull($uri->user());
        $this->assertNull($uri->password());
        $this->assertEquals('hypervel.org', $uri->host());
        $this->assertNull($uri->port());
        $this->assertEquals('docs/installation', $uri->path());
        $this->assertEquals([], $uri->query()->toArray());
        $this->assertEquals('', (string) $uri->query());
        $this->assertEquals('', $uri->query()->decode());
        $this->assertNull($uri->fragment());
        $this->assertEquals($originalUri, (string) $uri);

        $uri = Uri::of('https://taylor:password@hypervel.org/docs/installation?version=1#hello');

        $this->assertEquals('taylor', $uri->user());
        $this->assertEquals('password', $uri->password());
        $this->assertEquals('hello', $uri->fragment());
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

        $this->assertEquals('key_1=value&key_2[sub_field]=value&key_3[]=value&key_4[9]=value&key_5[][][foo][9]=bar&key.6=value&flag_value', $uri->query()->decode());
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
        $this->assertInstanceOf(HypervelStringable::class, $uri->toStringable());
        $this->assertSame($expected, $uri->toStringable()->toString());
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

        $this->assertEquals('age=38&role[title]=Developer&role[focus]=PHP&tags[0]=person&tags[1]=employee&flag=', $uri->query()->decode());
        $this->assertEquals('name=Taylor', $uri->replaceQuery(['name' => 'Taylor'])->query()->decode());

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

        $this->assertEquals('foo.bar=baz&foo[bar]=zab', $uri->withQuery(['foo.bar' => 'zab'])->query()->decode());
        $this->assertEquals('foo[bar]=zab', $uri->replaceQuery(['foo.bar' => 'zab'])->query()->decode());
    }

    public function testDecodingTheEntireUri(): void
    {
        $uri = Uri::of('https://hypervel.org/docs/11.x/installation')->withQuery(['tags' => ['first', 'second']]);

        $this->assertEquals('https://hypervel.org/docs/11.x/installation?tags[0]=first&tags[1]=second', $uri->decode());
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

        $this->assertEquals('existing=value&new=parameter', $uri->query()->decode());

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

        $this->assertEquals('name=Taylor&role[title]=Developer&role[focus]=PHP&tags[0]=person&tags[1]=employee', $uri->query()->decode());

        // Test partial array merging and preserving indexed arrays
        $uri = Uri::of('https://hypervel.org?name=Taylor&tags[0]=person');

        $uri = $uri->withQueryIfMissing([
            'name' => 'Changed',
            'age' => 38,
            'tags' => ['should', 'not', 'change'],
        ]);

        $this->assertEquals('name=Taylor&tags[0]=person&age=38', $uri->query()->decode());
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

        $this->assertEquals('https://hypervel.org', (string) $uri);
        $this->assertEquals('https://hypervel.org', (string) $uri->withQuery([]));
    }

    public function testPathSegments(): void
    {
        $uri = Uri::of('https://hypervel.org');

        $this->assertEquals([], $uri->pathSegments()->toArray());

        $uri = Uri::of('https://hypervel.org/one/two/three');

        $this->assertEquals(['one', 'two', 'three'], $uri->pathSegments()->toArray());
        $this->assertEquals('one', $uri->pathSegments()->first());

        $uri = Uri::of('https://hypervel.org/one/two/three?foo=bar');

        $this->assertEquals(3, $uri->pathSegments()->count());

        $uri = Uri::of('https://hypervel.org/one/two/three/?foo=bar');

        $this->assertEquals(3, $uri->pathSegments()->count());

        $uri = Uri::of('https://hypervel.org/one/two/three/#foo=bar');

        $this->assertEquals(3, $uri->pathSegments()->count());
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
