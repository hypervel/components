<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Carbon\Carbon;
use Hypervel\HttpMessage\Upload\UploadedFile;
use Hypervel\HttpMessage\Uri\Uri as HypervelUri;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Router\UrlGenerator as UrlGeneratorContract;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\Validation\Factory as ValidatorFactoryContract;
use Hypervel\Http\DispatchedRoute;
use Hypervel\Http\Request;
use Hypervel\HttpServer\Request as HttpServerRequest;
use Hypervel\HttpServer\Router\Dispatched;
use Hypervel\Router\RouteHandler;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable;
use Hypervel\Support\Uri;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Swow\Psr7\Message\ServerRequestPlusInterface;

/**
 * @internal
 * @coversNothing
 */
class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(ServerRequestInterface::class);
        Context::destroy('http.request.parsedData');
        Context::destroy(HttpServerRequest::class . '.properties.requestUri');
        Context::destroy(HttpServerRequest::class . '.properties.pathInfo');
    }

    public function testAllFiles()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([
            'file' => new UploadedFile('/tmp/tmp_name', 32, 0),
        ]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals(['file' => new UploadedFile('/tmp/tmp_name', 32, 0)], $request->allFiles());
    }

    public function testAnyFilled()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John', 'email' => '']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->anyFilled(['name', 'email']));
        $this->assertFalse($request->anyFilled(['age', 'email']));
    }

    public function testAll()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John', 'email' => '']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn(['foo' => 'bar']);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([
            'file' => new UploadedFile('/tmp/tmp_name', 32, 0),
            'avatar' => new UploadedFile('/tmp/avatar.jpg', 512, 0),
        ]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $allData = $request->all();
        $expected = [
            'name' => 'John',
            'email' => '',
            'foo' => 'bar',
            'file' => new UploadedFile('/tmp/tmp_name', 32, 0),
            'avatar' => new UploadedFile('/tmp/avatar.jpg', 512, 0),
        ];
        $this->assertEquals($expected, $allData);

        $specificData = $request->all(['name', 'avatar']);
        $expectedSpecific = [
            'name' => 'John',
            'avatar' => new UploadedFile('/tmp/avatar.jpg', 512, 0),
        ];
        $this->assertEquals($expectedSpecific, $specificData);
    }

    public function testBoolean()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['active' => '1', 'inactive' => '0']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->boolean('active'));
        $this->assertFalse($request->boolean('inactive'));
        $this->assertFalse($request->boolean('nonexistent', false));
    }

    public function testCollect()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John', 'age' => 30]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $collection = $request->collect();
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertEquals(['name' => 'John', 'age' => 30], $collection->all());

        $nameCollection = $request->collect('name');
        $this->assertEquals(['John'], $nameCollection->all());
    }

    public function testDate()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['created_at' => '2023-05-15']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $date = $request->date('created_at');
        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertEquals('2023-05-15', $date->toDateString());

        $formattedDate = $request->date('created_at', 'Y-m-d');
        $this->assertEquals('2023-05-15', $formattedDate->format('Y-m-d'));
    }

    public function testEnum()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['status' => 'active']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $enum = $request->enum('status', StatusEnum::class);
        $this->assertInstanceOf(StatusEnum::class, $enum);
        $this->assertEquals(StatusEnum::ACTIVE, $enum);
    }

    public function testExcept()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John', 'age' => 30, 'email' => 'john@example.com']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $result = $request->except(['age']);
        $this->assertEquals(['name' => 'John', 'email' => 'john@example.com'], $result);
    }

    public function testExists()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->exists('name'));
        $this->assertFalse($request->exists('age'));
    }

    public function testFilled()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John', 'email' => '']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->filled('name'));
        $this->assertFalse($request->filled('email'));
        $this->assertFalse($request->filled('age'));
    }

    public function testFloat()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['price' => '10.5']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals(10.5, $request->float('price'));
        $this->assertEquals(0.0, $request->float('nonexistent'));
    }

    public function testString()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $result = $request->string('name');
        $this->assertInstanceOf(Stringable::class, $result);
        $this->assertEquals('John', $result->toString());
    }

    public function testHasAny()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John', 'age' => 30]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->hasAny(['name', 'email']));
        $this->assertFalse($request->hasAny(['email', 'phone']));
    }

    public function testGetHost()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('HOST')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('HOST')->andReturn('example.com:8080');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals('example.com', $request->getHost());
    }

    public function testGetHttpHost()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('HOST')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('HOST')->andReturn('example.com:8080');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals('example.com:8080', $request->getHttpHost());
    }

    public function testGetPort()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('HOST')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('HOST')->andReturn('example.com:8080');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals(8080, $request->getPort());
    }

    public function testGetSchemeWithHttp()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri('http://localhost/path')
        );
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals('http', $request->getScheme());
    }

    public function testGetSchemeWithHttps()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri('https://localhost/path')
        );
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals('https', $request->getScheme());
    }

    public function testIsSecureWithHttp()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri('http://localhost/path')
        );
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertFalse($request->isSecure());
    }

    public function testIsSecureWithHttps()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri('https://localhost/path')
        );
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->isSecure());
    }

    public function testInteger()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['age' => '30']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals(30, $request->integer('age'));
        $this->assertEquals(0, $request->integer('nonexistent'));
    }

    public function testIsEmptyString()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => '', 'age' => '30']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->isEmptyString('name'));
        $this->assertFalse($request->isEmptyString('age'));
    }

    public function testIsJson()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('CONTENT_TYPE')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('CONTENT_TYPE')->andReturn('application/json');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->isJson());
    }

    public function testIsNotFilled()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => '', 'age' => '30']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->isNotFilled('name'));
        $this->assertFalse($request->isNotFilled('age'));
    }

    public function testKeys()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John', 'age' => 30]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals(['name', 'age'], $request->keys());
    }

    public function testMerge()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        Context::set('http.request.parsedData', ['name' => 'John']);
        $request = new Request();

        $newRequest = $request->merge(['age' => 30]);
        $this->assertEquals(['name' => 'John', 'age' => 30], $newRequest->all());
    }

    public function testReplace()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        Context::set('http.request.parsedData', ['name' => 'John', 'age' => 30]);
        $request = new Request();

        $newRequest = $request->replace(['name' => 'Foo']);
        $this->assertEquals(['name' => 'Foo', 'age' => 30], $newRequest->all());
    }

    public function testMergeIfMissing()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $newRequest = $request->mergeIfMissing(['name' => 'Jane', 'age' => 30]);
        $this->assertEquals(['name' => 'John', 'age' => 30], $newRequest->all());
    }

    public function testMissing()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->missing('age'));
        $this->assertFalse($request->missing('name'));
    }

    public function testOnly()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John', 'age' => 30, 'email' => 'john@example.com']);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $result = $request->only(['name', 'age']);
        $this->assertEquals(['name' => 'John', 'age' => 30], $result);
    }

    public function testSchemeAndHttpHost()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('HOST')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('HOST')->andReturn('example.com:8080');
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri('https://example.com:8080')
        );
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('https://example.com:8080', $request->schemeAndHttpHost());
    }

    public function testExpectsJson()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('X-Requested-With')->andReturn(true);
        $psrRequest->shouldReceive('hasHeader')->with('X-PJAX')->andReturn(false);
        $psrRequest->shouldReceive('hasHeader')->with('Accept')->andReturn(false);
        $psrRequest->shouldReceive('getHeaderLine')->with('X-Requested-With')->andReturn('XMLHttpRequest');
        $psrRequest->shouldReceive('getHeaderLine')->with('Accept')->andReturn('application/json');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->expectsJson());
    }

    public function testWantsJson()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('Accept')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('Accept')->andReturn('application/json');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->wantsJson());
    }

    public function testAccepts()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('Accept')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('Accept')->andReturn('application/json');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->accepts('application/json'));
    }

    public function testPrefers()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('Accept')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('Accept')->andReturn('application/json,text/html');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('application/json', $request->prefers(['application/json', 'text/html']));
    }

    public function testAcceptsAnyContentType()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('Accept')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('Accept')->andReturn('*/*');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->acceptsAnyContentType());
    }

    public function testAcceptsJson()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('Accept')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('Accept')->andReturn('application/json');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->acceptsJson());
    }

    public function testAcceptsHtml()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('Accept')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('Accept')->andReturn('text/html');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->acceptsHtml());
    }

    public function testWhenFilled()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['key' => 'value']);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $result = $request->whenFilled('key', function ($value) {
            return $value;
        });

        $this->assertSame('value', $result);

        $result = $request->whenFilled('foo', function ($value) {
            return $value;
        }, fn () => 'default');

        $this->assertSame('default', $result);
    }

    public function testWhenHas()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['key' => 'value']);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $result = $request->whenHas('key', function ($value) {
            return $value;
        });

        $this->assertSame('value', $result);

        $result = $request->whenHas('foo', function ($value) {
            return $value;
        }, fn () => 'default');

        $this->assertSame('default', $result);
    }

    public function testGetClientIp()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getHeaderLine')->with('x-real-ip')->andReturn('127.0.0.1');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('127.0.0.1', $request->getClientIp());
    }

    public function testIp()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getHeaderLine')->with('x-real-ip')->andReturn('127.0.0.1');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('127.0.0.1', $request->ip());
    }

    public function testFullUrlWithQuery()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn(['key' => 'value']);
        $psrRequest->shouldReceive('getServerParams')->andReturn([]);
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri('http://localhost/path')
        );

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('http://localhost/path?key=value&newkey=newvalue', $request->fullUrlWithQuery(['newkey' => 'newvalue']));
    }

    public function testFullUrlWithoutQuery()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn(['key' => 'value', 'foo' => 'bar']);
        $psrRequest->shouldReceive('getServerParams')->andReturn([]);
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri('http://localhost/path')
        );
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('http://localhost/path?key=value', $request->fullUrlWithoutQuery(['foo']));
    }

    public function testRoot()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('HOST')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('HOST')->andReturn('example.com:8080');
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri('https://example.com:8080')
        );
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('https://example.com:8080', $request->root());
    }

    public function testMethod()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getMethod')->andReturn('GET');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('GET', $request->method());
    }

    public function testUri()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn(['key' => 'value']);
        $psrRequest->shouldReceive('getServerParams')->andReturn([]);
        $psrRequest->shouldReceive('getUri')->andReturn(
            new HypervelUri($uri = 'http://localhost/path')
        );

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertInstanceOf(Uri::class, $request->uri());
        $this->assertSame($uri, (string) $request->uri());
    }

    public function testBearerToken()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('Authorization')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('Authorization')->andReturn('Bearer token');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('token', $request->bearerToken());
    }

    public function testGetAcceptableContentTypes()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('Accept')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('Accept')->andReturn('application/json,text/html');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame(['application/json', 'text/html'], $request->getAcceptableContentTypes());
    }

    public function testGetMimeType()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame('application/json', $request->getMimeType('json'));
    }

    public function testGetMimeTypes()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame(['application/json', 'application/x-json'], $request->getMimeTypes('json'));
    }

    public function testIsXmlHttpRequest()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('X-Requested-With')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('X-Requested-With')->andReturn('XMLHttpRequest');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->isXmlHttpRequest());
    }

    public function testAjax()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('X-Requested-With')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('X-Requested-With')->andReturn('XMLHttpRequest');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->ajax());
    }

    public function testPrefetch()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('X-MOZ')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('X-MOZ')->andReturn('prefetch');

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->prefetch());
    }

    public function testPjax()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('hasHeader')->with('X-PJAX')->andReturn(true);
        $psrRequest->shouldReceive('getHeaderLine')->with('X-PJAX')->andReturn('true');
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->pjax());
    }

    public function testHasSession()
    {
        $container = new Container();
        $container->instance(SessionContract::class, m::mock(SessionContract::class));
        Container::setInstance($container);
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->hasSession());
    }

    public function testSession()
    {
        $container = new Container();
        $container->instance(SessionContract::class, $session = m::mock(SessionContract::class));
        Container::setInstance($container);
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame($session, $request->session());
    }

    public function testGetPsr7Request()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertSame($psrRequest, $request->getPsr7Request());
    }

    public function testValidate()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getParsedBody')->andReturn(['name' => 'John Doe']);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $validatorFactory = m::mock(ValidatorFactoryContract::class);
        $validatorFactory->shouldReceive('validate')
            ->once()
            ->with(
                ['name' => 'John Doe'],
                ['name' => 'required|string|max:255'],
                [],
                []
            )
            ->andReturn(['name' => 'John Doe']);

        $container = new Container();
        $container->instance(ValidatorFactoryContract::class, $validatorFactory);
        Container::setInstance($container);

        $result = $request->validate(
            ['name' => 'required|string|max:255']
        );

        $this->assertEquals(['name' => 'John Doe'], $result);
    }

    public function testUserResolver()
    {
        $request = new Request();
        $request->setUserResolver(function () {
            return 'user';
        });

        $this->assertSame('user', $request->user());
    }

    public function testHasValidSignature()
    {
        $request = new Request();

        $urlGenerator = m::mock(UrlGeneratorContract::class);
        $urlGenerator->shouldReceive('hasValidSignature')
            ->once()
            ->with($request, true)
            ->andReturn(true);

        $container = new Container();
        $container->instance(UrlGeneratorContract::class, $urlGenerator);
        Container::setInstance($container);

        $this->assertTrue($request->hasValidSignature());
    }

    public function testHasValidRelativeSignature()
    {
        $request = new Request();

        $urlGenerator = m::mock(UrlGeneratorContract::class);
        $urlGenerator->shouldReceive('hasValidSignature')
            ->once()
            ->with($request, false)
            ->andReturn(true);

        $container = new Container();
        $container->instance(UrlGeneratorContract::class, $urlGenerator);
        Container::setInstance($container);

        $this->assertTrue($request->hasValidRelativeSignature());
    }

    public function testHasValidSignatureWhileIgnoring()
    {
        $request = new Request();

        $urlGenerator = m::mock(UrlGeneratorContract::class);
        $urlGenerator->shouldReceive('hasValidSignature')
            ->once()
            ->with($request, true, [])
            ->andReturn(true);

        $container = new Container();
        $container->instance(UrlGeneratorContract::class, $urlGenerator);
        Container::setInstance($container);

        $this->assertTrue($request->hasValidSignatureWhileIgnoring());
    }

    public function testHasValidRelativeSignatureWhileIgnoring()
    {
        $request = new Request();

        $urlGenerator = m::mock(UrlGeneratorContract::class);
        $urlGenerator->shouldReceive('hasValidSignature')
            ->once()
            ->with($request, false, [])
            ->andReturn(true);

        $container = new Container();
        $container->instance(UrlGeneratorContract::class, $urlGenerator);
        Container::setInstance($container);

        $this->assertTrue($request->hasValidRelativeSignatureWhileIgnoring());
    }

    public function testGetPsr7RequestWithRuntimeException()
    {
        Context::set(ServerRequestInterface::class, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RequestContext is not set, please use RequestContext::set() to set the request.');

        (new Request())->getPsr7Request();
    }

    public function testGetDispatchedRoute()
    {
        $handler = new RouteHandler('TestController@index', '/test', ['as' => 'test.index']);
        $dispatched = new DispatchedRoute([1, $handler, ['id' => '123']]);

        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getAttribute')
            ->with(Dispatched::class)
            ->andReturn($dispatched);

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $result = $request->getDispatchedRoute();
        $this->assertInstanceOf(Dispatched::class, $result);
        $this->assertSame($dispatched, $result);
    }

    public function testSegment()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getServerParams')
            ->andReturn(['request_uri' => '/users/123/posts/456']);

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertEquals('users', $request->segment(1));
        $this->assertEquals('123', $request->segment(2));
        $this->assertEquals('posts', $request->segment(3));
        $this->assertEquals('456', $request->segment(4));
        $this->assertNull($request->segment(5));
        $this->assertEquals('default', $request->segment(5, 'default'));
    }

    public function testSegmentWithRootPath()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getServerParams')
            ->andReturn(['request_uri' => '/']);

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertNull($request->segment(1));
        $this->assertEquals('default', $request->segment(1, 'default'));
    }

    public function testSegments()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getServerParams')->andReturn(['request_uri' => '/api/v1/users/123']);

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $segments = $request->segments();
        $this->assertEquals(['api', 'v1', 'users', '123'], $segments);
    }

    public function testSegmentsWithRootPath()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getServerParams')->andReturn(['request_uri' => '/']);

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $segments = $request->segments();
        $this->assertEquals([], $segments);
    }

    public function testSegmentsWithSingleSegment()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getServerParams')->andReturn(['request_uri' => '/home']);

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $segments = $request->segments();
        $this->assertEquals(['home'], $segments);
    }

    public function testRouteIs()
    {
        $handler = new RouteHandler('TestController@index', '/test', ['as' => 'user.profile']);
        $dispatched = new DispatchedRoute([1, $handler, []]);

        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getAttribute')
            ->with(Dispatched::class)
            ->andReturn($dispatched);

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->routeIs('user.profile'));
        $this->assertTrue($request->routeIs('user.*'));
        $this->assertTrue($request->routeIs('*.profile'));
        $this->assertFalse($request->routeIs('admin.profile'));
        $this->assertFalse($request->routeIs('user.settings'));

        // Test multiple patterns
        $this->assertTrue($request->routeIs('admin.*', 'user.*'));
        $this->assertFalse($request->routeIs('admin.*', 'guest.*'));
    }

    public function testRouteIsWithNoRouteName()
    {
        $handler = new RouteHandler('TestController@index', '/test', []);
        $dispatched = new DispatchedRoute([1, $handler, []]);

        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getAttribute')
            ->with(Dispatched::class)
            ->andReturn($dispatched);

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertFalse($request->routeIs('any.route'));
        $this->assertFalse($request->routeIs('*'));
    }

    public function testFullUrlIs()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn(['key' => 'value']);
        $psrRequest->shouldReceive('getServerParams')->andReturn(['query_string' => 'key=value', 'request_uri' => '/api/users?key=value']);
        $psrRequest->shouldReceive('getUri')->andReturn(new HypervelUri('http://localhost/api/users'));

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->fullUrlIs('http://localhost/api/users?key=value'));
        $this->assertTrue($request->fullUrlIs('http://localhost/api/*'));
        $this->assertTrue($request->fullUrlIs('*://localhost/api/users?key=value'));
        $this->assertFalse($request->fullUrlIs('http://localhost/api/posts?key=value'));
        $this->assertFalse($request->fullUrlIs('https://localhost/api/users?key=value'));

        // Test multiple patterns
        $this->assertTrue($request->fullUrlIs('http://example.com/*', 'http://localhost/api/*'));
        $this->assertFalse($request->fullUrlIs('http://example.com/*', 'https://localhost/*'));
    }

    public function testFullUrlIsWithoutQuery()
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getServerParams')->andReturn(['query_string' => '', 'request_uri' => '/api/users']);
        $psrRequest->shouldReceive('getUri')->andReturn(new HypervelUri('http://localhost/api/users'));

        Context::set(ServerRequestInterface::class, $psrRequest);
        $request = new Request();

        $this->assertTrue($request->fullUrlIs('http://localhost/api/users'));
        $this->assertTrue($request->fullUrlIs('http://localhost/api/*'));
        $this->assertFalse($request->fullUrlIs('http://localhost/api/users?key=value'));
    }
}

enum StatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
