<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage;

use Hyperf\Codec\Json;
use Hyperf\Codec\Xml;
use Hypervel\Container\Container;
use Hypervel\HttpMessage\Exceptions\BadRequestHttpException;
use Hypervel\HttpMessage\Server\Request;
use Hypervel\HttpMessage\Server\Request\JsonParser;
use Hypervel\HttpMessage\Server\Request\Parser;
use Hypervel\HttpMessage\Server\Request\XmlParser;
use Hypervel\HttpMessage\Server\RequestParserInterface;
use Hypervel\HttpMessage\Stream\SwooleStream;
use Hypervel\Tests\HttpMessage\Stub\ParserStub;
use Hypervel\Tests\HttpMessage\Stub\Server\RequestStub;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use Swoole\Http\Request as SwooleRequest;

/**
 * @internal
 * @coversNothing
 */
class ServerRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        RequestStub::setParser(null);
    }

    public function testNormalizeParsedBody()
    {
        $this->getContainer();

        $data = ['id' => 1];
        $json = ['name' => 'Hyperf'];

        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('');

        $this->assertSame($data, RequestStub::normalizeParsedBody($data));
        $this->assertSame($data, RequestStub::normalizeParsedBody($data, $request));

        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('application/xml; charset=utf-8');
        $request->shouldReceive('getBody')->andReturn(new SwooleStream(Xml::toXml($json)));

        $this->assertSame($json, RequestStub::normalizeParsedBody($json, $request));

        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('application/json; charset=utf-8');
        $request->shouldReceive('getBody')->andReturn(new SwooleStream(Json::encode($json)));
        $this->assertSame($json, RequestStub::normalizeParsedBody($data, $request));
    }

    public function testNormalizeParsedBodyException()
    {
        $this->expectException(BadRequestHttpException::class);

        $this->getContainer();

        $json = ['name' => 'Hyperf'];
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('application/json; charset=utf-8');
        $request->shouldReceive('getBody')->andReturn(new SwooleStream('xxxx'));
        $this->assertSame([], RequestStub::normalizeParsedBody($json, $request));
    }

    public function testXmlNormalizeParsedBodyException()
    {
        $this->expectException(BadRequestHttpException::class);

        $this->getContainer();

        $json = ['name' => 'Hyperf'];
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('application/xml; charset=utf-8');
        $request->shouldReceive('getBody')->andReturn(new SwooleStream('xxxx'));
        $this->assertSame([], RequestStub::normalizeParsedBody($json, $request));
    }

    public function testNormalizeEmptyBody()
    {
        $this->getContainer();

        $json = ['name' => 'Hyperf'];
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('application/json; charset=utf-8');
        $request->shouldReceive('getBody')->andReturn(new SwooleStream(''));
        $this->assertSame($json, RequestStub::normalizeParsedBody($json, $request));

        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('application/json; charset=utf-8');
        $request->shouldReceive('getBody')->andReturn(new SwooleStream(''));
        $this->assertSame([], RequestStub::normalizeParsedBody([], $request));
    }

    public function testNormalizeParsedBodyInvalidContentType()
    {
        $this->getContainer();

        $data = ['id' => 1];
        $json = ['name' => 'Hyperf'];

        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('application/JSON');
        $request->shouldReceive('getBody')->andReturn(new SwooleStream(json_encode($json)));
        $this->assertSame($json, RequestStub::normalizeParsedBody($data, $request));
    }

    public function testOverrideRequestParser()
    {
        $this->getContainer();
        $this->assertSame(Parser::class, get_class(RequestStub::getParser()));

        RequestStub::setParser(new ParserStub());
        $json = ['name' => 'Hyperf'];

        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getHeaderLine')->with('content-type')->andReturn('application/JSON');
        $request->shouldReceive('getBody')->andReturn(new SwooleStream(json_encode($json)));
        $this->assertSame(['mock' => true], RequestStub::normalizeParsedBody([], $request));
        $this->assertSame(ParserStub::class, get_class(RequestStub::getParser()));
    }

    public function testGetUriFromGlobals()
    {
        $swooleRequest = m::mock(SwooleRequest::class);
        $data = ['name' => 'Hyperf'];
        $swooleRequest->shouldReceive('rawContent')->andReturn(Json::encode($data));
        $swooleRequest->server = ['server_port' => 9501];
        $request = Request::loadFromSwooleRequest($swooleRequest);
        $uri = $request->getUri();
        $this->assertSame(9501, $uri->getPort());

        $swooleRequest = m::mock(SwooleRequest::class);
        $data = ['name' => 'Hyperf'];
        $swooleRequest->shouldReceive('rawContent')->andReturn(Json::encode($data));
        $swooleRequest->header = ['host' => '127.0.0.1'];
        $swooleRequest->server = ['server_port' => 9501];
        $request = Request::loadFromSwooleRequest($swooleRequest);
        $uri = $request->getUri();
        $this->assertSame(null, $uri->getPort());
    }

    public function testParseHost()
    {
        $hostStrIPv4 = '192.168.119.100:9501';
        $hostStrIPv6 = '[fe80::a464:1aff:fe88:7b5a]:9502';
        $objReflectClass = new ReflectionClass(Request::class);
        $method = $objReflectClass->getMethod('parseHost');

        $resIPv4 = $method->invokeArgs(null, [$hostStrIPv4]);
        $this->assertSame('192.168.119.100', $resIPv4[0]);
        $this->assertSame(9501, $resIPv4[1]);

        $resIPv6 = $method->invokeArgs(null, [$hostStrIPv6]);
        $this->assertSame('[fe80::a464:1aff:fe88:7b5a]', $resIPv6[0]);
        $this->assertSame(9502, $resIPv6[1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid host: ');
        $method->invokeArgs(null, ['']);
    }

    /**
     * @dataProvider getIPv6Examples
     */
    public function testGetUriFromGlobalsForIPv6Host(string $originHost, string $host, ?int $port)
    {
        $swooleRequest = m::mock(SwooleRequest::class);
        $data = ['name' => 'Hyperf'];
        $swooleRequest->shouldReceive('rawContent')->andReturn(Json::encode($data));

        $swooleRequest->server = [
            'http_host' => $originHost,
        ];
        $request = Request::loadFromSwooleRequest($swooleRequest);
        $uri = $request->getUri();
        $this->assertSame($port, $uri->getPort());
        $this->assertSame($host, $uri->getHost());

        $swooleRequest->server = [];
        $swooleRequest->header = [
            'host' => $originHost,
        ];
        $request = Request::loadFromSwooleRequest($swooleRequest);
        $uri = $request->getUri();
        $this->assertSame($port, $uri->getPort());
        $this->assertSame($host, $uri->getHost());
    }

    public static function getIPv6Examples(): array
    {
        return [
            ['localhost:9501', 'localhost', 9501],
            ['localhost:', 'localhost', null],
            ['localhost', 'localhost', null],
            ['[2a00:f48:1008::212:183:10]', '[2a00:f48:1008::212:183:10]', null],
            ['[2a00:f48:1008::212:183:10]:9501', '[2a00:f48:1008::212:183:10]', 9501],
            ['[2a00:f48:1008::212:183:10]:', '[2a00:f48:1008::212:183:10]', null],
        ];
    }

    protected function getContainer()
    {
        $container = m::mock(Container::class);

        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('make')->with(JsonParser::class, m::any())->andReturn(new JsonParser());
        $container->shouldReceive('make')->with(XmlParser::class, m::any())->andReturn(new XmlParser());
        $container->shouldReceive('make')->with(RequestParserInterface::class)->andReturn(new Parser());

        Container::setInstance($container);

        return $container;
    }
}
