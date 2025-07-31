<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Uri;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SupportUriTest extends TestCase
{
    public function testBasicUriInteractions(): void
    {
        $uri = Uri::of($originalUri = 'https://hypervel.org/docs/installation');

        $this->assertEquals('https', $uri->scheme());
        $this->assertNull($uri->user());
        $this->assertNull($uri->password());
        $this->assertEquals('laravel.com', $uri->host());
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

        $uri = $uri->withHost('laravel.com')
            ->withScheme('https')
            ->withUser('taylor', 'password')
            ->withPath('/docs/installation')
            ->withPort(80)
            ->withQuery(['version' => 1])
            ->withFragment('hello');

        $this->assertEquals('https://taylor:password@hypervel.org:80/docs/installation?version=1#hello', (string) $uri);
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
