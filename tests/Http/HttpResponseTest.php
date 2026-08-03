<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\HttpResponseTest;

use BadMethodCallException;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Contracts\Support\MessageProvider;
use Hypervel\Contracts\Support\Renderable;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Session\Store;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Tests\TestCase;
use JsonSerializable;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use TypeError;

class HttpResponseTest extends TestCase
{
    public function testConstructorInitializesStatusHeadersContentAndOriginalContent(): void
    {
        $response = new Response(['name' => 'Taylor'], 201, ['X-Test' => 'yes']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('yes', $response->headers->get('X-Test'));
        $this->assertSame('1.0', $response->getProtocolVersion());
        $this->assertSame('{"name":"Taylor"}', $response->getContent());
        $this->assertSame(['name' => 'Taylor'], $response->getOriginalContent());
    }

    public function testJsonResponsesAreConvertedAndHeadersAreSet(): void
    {
        $response = new Response(new ArrayableStub);
        $this->assertSame('{"foo":"bar"}', $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $response = new Response(new JsonableStub);
        $this->assertSame('foo', $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $response = new Response(new ArrayableAndJsonableStub);
        $this->assertSame('{"foo":"bar"}', $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $response = new Response;
        $response->setContent(['foo' => 'bar']);
        $this->assertSame('{"foo":"bar"}', $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $response = new Response(new JsonSerializableStub);
        $this->assertSame('{"foo":"bar"}', $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $response = new Response(new ArrayableStub);
        $this->assertSame('{"foo":"bar"}', $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $response->setContent('{"foo": "bar"}');
        $this->assertSame('{"foo": "bar"}', $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testRenderablesAreRendered(): void
    {
        $mock = m::mock(Renderable::class);
        $mock->shouldReceive('render')->once()->andReturn('foo');
        $response = new Response($mock);
        $this->assertSame('foo', $response->getContent());
    }

    public function testHeader(): void
    {
        $response = new Response;
        $this->assertNull($response->headers->get('foo'));
        $response->header('foo', 'bar');
        $this->assertSame('bar', $response->headers->get('foo'));
        $response->header('foo', 'baz', false);
        $this->assertSame('bar', $response->headers->get('foo'));
        $response->header('foo', 'baz');
        $this->assertSame('baz', $response->headers->get('foo'));
    }

    public function testWithCookie(): void
    {
        $response = new Response;
        $this->assertCount(0, $response->headers->getCookies());
        $this->assertSame($response, $response->withCookie(new Cookie('foo', 'bar')));
        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('foo', $cookies[0]->getName());
        $this->assertSame('bar', $cookies[0]->getValue());
    }

    public function testWithCookies(): void
    {
        $response = new Response;
        $this->assertCount(0, $response->headers->getCookies());
        $this->assertSame($response, $response->withCookies([
            new Cookie('foo', 'bar'),
            new Cookie('baz', 'qux'),
        ]));
        $cookies = $response->headers->getCookies();
        $this->assertCount(2, $cookies);
        $this->assertSame('foo', $cookies[0]->getName());
        $this->assertSame('bar', $cookies[0]->getValue());
        $this->assertSame('baz', $cookies[1]->getName());
        $this->assertSame('qux', $cookies[1]->getValue());
    }

    public function testResponseCookiesInheritRequestSecureState(): void
    {
        $cookie = Cookie::create('foo', 'bar');

        $response = new Response('foo');
        $response->headers->setCookie($cookie);

        $request = Request::create('/', 'GET');
        $response->prepare($request);

        $this->assertFalse($cookie->isSecure());

        $request = Request::create('https://localhost/', 'GET');
        $response->prepare($request);

        $this->assertTrue($cookie->isSecure());
    }

    public function testGetOriginalContent(): void
    {
        $arr = ['foo' => 'bar'];
        $response = new Response;
        $response->setContent($arr);
        $this->assertSame($arr, $response->getOriginalContent());
    }

    public function testGetOriginalContentRetrievesTheFirstOriginalContent(): void
    {
        $previousResponse = new Response(['foo' => 'bar']);
        $response = new Response($previousResponse);

        // ResponseHeaderBag initializes Date once, so re-stringifying this response is stable.
        $this->assertSame((string) $previousResponse, $response->getContent());
        $this->assertSame(['foo' => 'bar'], $response->getOriginalContent());
    }

    #[DataProvider('scalarContentProvider')]
    public function testScalarContentUsesLaravelWeakModeCoercion(mixed $original, string $content): void
    {
        $response = new Response($original);

        $this->assertSame($content, $response->getContent());
        $this->assertSame($original, $response->getOriginalContent());
    }

    public static function scalarContentProvider(): array
    {
        return [
            'integer' => [42, '42'],
            'float' => [0.1 + 0.2, '0.3'],
            'true' => [true, '1'],
            'false' => [false, ''],
            'null' => [null, ''],
        ];
    }

    public function testNonStringableContentIsRejected(): void
    {
        $this->expectException(TypeError::class);

        new Response(new class {});
    }

    public function testSetAndRetrieveStatusCode(): void
    {
        $response = new Response('foo');
        $response->setStatusCode(404);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSetStatusCodeAndRetrieveStatusText(): void
    {
        $response = new Response('foo');
        $response->setStatusCode(404);
        $this->assertSame('Not Found', $response->statusText());
    }

    public function testOnlyInputOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $response->setSession($session = m::mock(Store::class));
        $session->shouldReceive('flashInput')->once()->with(['name' => 'Taylor']);
        $response->onlyInput('name');
    }

    public function testExceptInputOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $response->setSession($session = m::mock(Store::class));
        $session->shouldReceive('flashInput')->once()->with(['name' => 'Taylor']);
        $response->exceptInput('age');
    }

    public function testFlashingErrorsOnRedirect(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $response->setSession($session = m::mock(Store::class));
        $session->shouldReceive('get')->with('errors', m::type(ViewErrorBag::class))->andReturn(new ViewErrorBag);
        $session->shouldReceive('flash')->once()->with('errors', m::type(ViewErrorBag::class));
        $provider = m::mock(MessageProvider::class);
        $provider->shouldReceive('getMessageBag')->once()->andReturn(new MessageBag);
        $response->withErrors($provider);
    }

    public function testSettersGettersOnRequest(): void
    {
        $response = new RedirectResponse('foo.bar');
        $this->assertNull($response->getRequest());
        $this->assertNull($response->getSession());

        $request = Request::create('/', 'GET');
        $session = m::mock(Store::class);
        $response->setRequest($request);
        $response->setSession($session);
        $this->assertSame($request, $response->getRequest());
        $this->assertSame($session, $response->getSession());
    }

    public function testRedirectWithErrorsArrayConvertsToMessageBag(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $response->setSession($session = m::mock(Store::class));
        $session->shouldReceive('get')->with('errors', m::type(ViewErrorBag::class))->andReturn(new ViewErrorBag);
        $session->shouldReceive('flash')->once()->with('errors', m::type(ViewErrorBag::class));
        $provider = ['foo' => 'bar'];
        $response->withErrors($provider);
    }

    public function testWithHeaders(): void
    {
        $response = new Response(null, 200, ['foo' => 'bar']);
        $this->assertSame('bar', $response->headers->get('foo'));

        $response->withHeaders(['foo' => 'BAR', 'bar' => 'baz']);
        $this->assertSame('BAR', $response->headers->get('foo'));
        $this->assertSame('baz', $response->headers->get('bar'));

        $responseMessageBag = new ResponseHeaderBag(['bar' => 'BAZ', 'titi' => 'toto']);
        $response->withHeaders($responseMessageBag);
        $this->assertSame('BAZ', $response->headers->get('bar'));
        $this->assertSame('toto', $response->headers->get('titi'));

        $headerBag = new HeaderBag(['bar' => 'BAAA', 'titi' => 'TATA']);
        $response->withHeaders($headerBag);
        $this->assertSame('BAAA', $response->headers->get('bar'));
        $this->assertSame('TATA', $response->headers->get('titi'));
    }

    public function testWithoutHeader(): void
    {
        $response = new Response(null, 200, ['foo' => 'bar', 'baz' => 'qux', 'zal' => 'ter']);
        $this->assertSame('bar', $response->headers->get('foo'));
        $this->assertSame('qux', $response->headers->get('baz'));
        $this->assertSame('ter', $response->headers->get('zal'));

        // Test removing single header
        $result = $response->withoutHeader('foo');
        $this->assertSame($response, $result);
        $this->assertNull($response->headers->get('foo'));
        $this->assertSame('qux', $response->headers->get('baz'));
        $this->assertSame('ter', $response->headers->get('zal'));

        // Test removing multiple headers at once
        $response->withoutHeader(['baz', 'zal']);
        $this->assertNull($response->headers->get('baz'));
        $this->assertNull($response->headers->get('zal'));
    }

    public function testMagicCall(): void
    {
        $response = new RedirectResponse('foo.bar');
        $response->setRequest(Request::create('/', 'GET', ['name' => 'Taylor', 'age' => 26]));
        $response->setSession($session = m::mock(Store::class));
        $session->shouldReceive('flash')->once()->with('foo', 'bar');
        $response->withFoo('bar');
    }

    public function testMagicCallException(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method Hypervel\Http\RedirectResponse::doesNotExist()');

        $response = new RedirectResponse('foo.bar');
        $response->doesNotExist('bar');
    }
}

class ArrayableStub implements Arrayable
{
    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return ['foo' => 'bar'];
    }
}

class ArrayableAndJsonableStub implements Arrayable, Jsonable
{
    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return '{"foo":"bar"}';
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [];
    }
}

class JsonableStub implements Jsonable
{
    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return 'foo';
    }
}

class JsonSerializableStub implements JsonSerializable
{
    /**
     * Specify data which should be serialized to JSON.
     */
    public function jsonSerialize(): array
    {
        return ['foo' => 'bar'];
    }
}
