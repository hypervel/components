<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cookie\Middleware;

use Hypervel\Container\Container;
use Hypervel\Contracts\Encryption\Encrypter as EncrypterContract;
use Hypervel\Cookie\CookieJar;
use Hypervel\Cookie\CookieValuePrefix;
use Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Encryption\Encrypter;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Routing\Controller;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @internal
 * @coversNothing
 */
class EncryptCookiesTest extends TestCase
{
    protected Container $container;

    protected Router $router;

    protected string $setCookiePath = 'cookie/set';

    protected string $queueCookiePath = 'cookie/queue';

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        $this->container->singleton(EncrypterContract::class, function () {
            return new Encrypter(str_repeat('a', 16));
        });

        $this->router = new Router(new Dispatcher, $this->container);

        EncryptCookiesTestMiddleware::flushState();
        EncryptCookiesTestMiddleware::except(['globally_unencrypted_cookie']);
    }

    protected function tearDown(): void
    {
        EncryptCookiesTestMiddleware::flushState();

        parent::tearDown();
    }

    public function testSetCookieEncryption()
    {
        $this->router->get($this->setCookiePath, [
            'middleware' => EncryptCookiesTestMiddleware::class,
            'uses' => EncryptCookiesTestController::class . '@setCookies',
        ]);

        $response = $this->router->dispatch(Request::create($this->setCookiePath, 'GET'));

        $cookies = $response->headers->getCookies();
        $this->assertCount(5, $cookies);
        $this->assertSame('encrypted_cookie', $cookies[0]->getName());
        $this->assertNotSame('value', $cookies[0]->getValue());
        $this->assertSame('encrypted[array_cookie]', $cookies[1]->getName());
        $this->assertNotSame('value', $cookies[1]->getValue());
        $this->assertSame('encrypted[nested][array_cookie]', $cookies[2]->getName());
        $this->assertSame('unencrypted_cookie', $cookies[3]->getName());
        $this->assertSame('value', $cookies[3]->getValue());
        $this->assertSame('globally_unencrypted_cookie', $cookies[4]->getName());
        $this->assertSame('value', $cookies[4]->getValue());
    }

    public function testQueuedCookieEncryption()
    {
        $this->router->get($this->queueCookiePath, [
            'middleware' => [EncryptCookiesTestMiddleware::class, AddQueuedCookiesToResponseTestMiddleware::class],
            'uses' => EncryptCookiesTestController::class . '@queueCookies',
        ]);

        $response = $this->router->dispatch(Request::create($this->queueCookiePath, 'GET'));

        $cookies = $response->headers->getCookies();
        $this->assertCount(5, $cookies);
        $this->assertSame('encrypted_cookie', $cookies[0]->getName());
        $this->assertNotSame('value', $cookies[0]->getValue());
        $this->assertSame('encrypted[array_cookie]', $cookies[1]->getName());
        $this->assertNotSame('value', $cookies[1]->getValue());
        $this->assertSame('encrypted[nested][array_cookie]', $cookies[2]->getName());
        $this->assertNotSame('value', $cookies[2]->getValue());
        $this->assertSame('unencrypted_cookie', $cookies[3]->getName());
        $this->assertSame('value', $cookies[3]->getValue());
        $this->assertSame('globally_unencrypted_cookie', $cookies[4]->getName());
        $this->assertSame('value', $cookies[4]->getValue());
    }

    public function testCookieDecryption()
    {
        $cookies = [
            'encrypted_cookie' => $this->getEncryptedCookieValue('encrypted_cookie', 'value'),
            'encrypted' => [
                'array_cookie' => $this->getEncryptedCookieValue('encrypted[array_cookie]', 'value'),
                'nested' => [
                    'array_cookie' => $this->getEncryptedCookieValue('encrypted[nested][array_cookie]', 'value'),
                ],
            ],
            'unencrypted_cookie' => 'value',
            'globally_unencrypted_cookie' => 'value',
        ];

        $this->container->make(EncryptCookiesTestMiddleware::class)->handle(
            Request::create('/cookie/read', 'GET', [], $cookies),
            function ($request) {
                $cookies = $request->cookies->all();
                $this->assertCount(4, $cookies);
                $this->assertArrayHasKey('encrypted_cookie', $cookies);
                $this->assertSame('value', $cookies['encrypted_cookie']);
                $this->assertArrayHasKey('encrypted', $cookies);
                $this->assertArrayHasKey('array_cookie', $cookies['encrypted']);
                $this->assertSame('value', $cookies['encrypted']['array_cookie']);
                $this->assertArrayHasKey('nested', $cookies['encrypted']);
                $this->assertArrayHasKey('array_cookie', $cookies['encrypted']['nested']);
                $this->assertSame('value', $cookies['encrypted']['nested']['array_cookie']);
                $this->assertArrayHasKey('unencrypted_cookie', $cookies);
                $this->assertSame('value', $cookies['unencrypted_cookie']);
                $this->assertArrayHasKey('globally_unencrypted_cookie', $cookies);
                $this->assertSame('value', $cookies['globally_unencrypted_cookie']);

                return new Response;
            }
        );
    }

    public function testOnlyEncryptsSpecifiedCookies()
    {
        EncryptCookiesTestMiddleware::flushState();
        EncryptCookiesTestMiddleware::only(['encrypted_cookie']);

        $this->router->get($this->setCookiePath, [
            'middleware' => EncryptCookiesTestMiddleware::class,
            'uses' => EncryptCookiesTestController::class . '@setCookies',
        ]);

        $response = $this->router->dispatch(Request::create($this->setCookiePath, 'GET'));

        $cookies = $response->headers->getCookies();
        $this->assertCount(5, $cookies);
        // encrypted_cookie is in the only list — should be encrypted
        $this->assertSame('encrypted_cookie', $cookies[0]->getName());
        $this->assertNotSame('value', $cookies[0]->getValue());
        // All others should pass through unencrypted
        $this->assertSame('encrypted[array_cookie]', $cookies[1]->getName());
        $this->assertSame('value', $cookies[1]->getValue());
        $this->assertSame('encrypted[nested][array_cookie]', $cookies[2]->getName());
        $this->assertSame('value', $cookies[2]->getValue());
        $this->assertSame('unencrypted_cookie', $cookies[3]->getName());
        $this->assertSame('value', $cookies[3]->getValue());
        $this->assertSame('globally_unencrypted_cookie', $cookies[4]->getName());
        $this->assertSame('value', $cookies[4]->getValue());
    }

    public function testOnlyDecryptsSpecifiedCookies()
    {
        EncryptCookiesTestMiddleware::flushState();
        EncryptCookiesTestMiddleware::only(['encrypted_cookie']);

        $cookies = [
            'encrypted_cookie' => $this->getEncryptedCookieValue('encrypted_cookie', 'value'),
            'unencrypted_cookie' => 'plain-value',
        ];

        $this->container->make(EncryptCookiesTestMiddleware::class)->handle(
            Request::create('/cookie/read', 'GET', [], $cookies),
            function ($request) {
                $cookies = $request->cookies->all();
                $this->assertSame('value', $cookies['encrypted_cookie']);
                // Not in the only list — should pass through as-is
                $this->assertSame('plain-value', $cookies['unencrypted_cookie']);

                return new Response;
            }
        );
    }

    public function testOnlyTakesPrecedenceOverExcept()
    {
        EncryptCookiesTestMiddleware::flushState();
        // Set up both: except says "don't encrypt unencrypted_cookie",
        // only says "only encrypt encrypted_cookie"
        EncryptCookiesTestMiddleware::except(['unencrypted_cookie']);
        EncryptCookiesTestMiddleware::only(['encrypted_cookie']);

        $middleware = $this->container->make(EncryptCookiesTestMiddleware::class);

        // encrypted_cookie is in the only list — should NOT be disabled
        $this->assertFalse($middleware->isDisabled('encrypted_cookie'));
        // unencrypted_cookie is not in the only list — should be disabled
        // (only takes precedence, except is ignored)
        $this->assertTrue($middleware->isDisabled('unencrypted_cookie'));
        // globally_unencrypted_cookie is not in the only list — should be disabled
        $this->assertTrue($middleware->isDisabled('globally_unencrypted_cookie'));
        // random cookie not in any list — should be disabled
        $this->assertTrue($middleware->isDisabled('random_cookie'));
    }

    public function testOnlyTakesPrecedenceOverInstanceExcept()
    {
        EncryptCookiesTestMiddleware::flushState();
        EncryptCookiesTestMiddleware::only(['session']);

        $middleware = $this->container->make(EncryptCookiesTestMiddleware::class);

        // The instance $except has 'unencrypted_cookie', but only() takes precedence
        $this->assertTrue($middleware->isDisabled('unencrypted_cookie'));
        $this->assertFalse($middleware->isDisabled('session'));
    }

    public function testOnlyWithMultipleCalls()
    {
        EncryptCookiesTestMiddleware::flushState();
        EncryptCookiesTestMiddleware::only(['session']);
        EncryptCookiesTestMiddleware::only(['XSRF-TOKEN']);

        $middleware = $this->container->make(EncryptCookiesTestMiddleware::class);

        $this->assertFalse($middleware->isDisabled('session'));
        $this->assertFalse($middleware->isDisabled('XSRF-TOKEN'));
        $this->assertTrue($middleware->isDisabled('other_cookie'));
    }

    public function testOnlyWithEmptyArrayDoesNotActivateOptIn()
    {
        EncryptCookiesTestMiddleware::flushState();
        EncryptCookiesTestMiddleware::except(['unencrypted_cookie']);

        $middleware = $this->container->make(EncryptCookiesTestMiddleware::class);

        // With no only() call, except() should work normally
        $this->assertTrue($middleware->isDisabled('unencrypted_cookie'));
        $this->assertFalse($middleware->isDisabled('encrypted_cookie'));
    }

    public function testFlushStateClearsBothExceptAndOnly()
    {
        EncryptCookiesTestMiddleware::except(['foo']);
        EncryptCookiesTestMiddleware::only(['bar']);

        EncryptCookiesTestMiddleware::flushState();

        $middleware = $this->container->make(EncryptCookiesTestMiddleware::class);

        // After flush, only the instance $except applies (from the subclass)
        $this->assertTrue($middleware->isDisabled('unencrypted_cookie'));
        $this->assertFalse($middleware->isDisabled('foo'));
        $this->assertFalse($middleware->isDisabled('bar'));
        $this->assertFalse($middleware->isDisabled('encrypted_cookie'));
    }

    public function testDisableForWorksWithExceptMode()
    {
        EncryptCookiesTestMiddleware::flushState();

        $middleware = $this->container->make(EncryptCookiesTestMiddleware::class);
        $middleware->disableFor('runtime_cookie');

        $this->assertTrue($middleware->isDisabled('runtime_cookie'));
        $this->assertFalse($middleware->isDisabled('encrypted_cookie'));
    }

    public function testDisableForIsIgnoredWhenOnlyIsActive()
    {
        EncryptCookiesTestMiddleware::flushState();
        EncryptCookiesTestMiddleware::only(['session']);

        $middleware = $this->container->make(EncryptCookiesTestMiddleware::class);
        $middleware->disableFor('session');

        // only() takes precedence — session is in the only list, so NOT disabled
        $this->assertFalse($middleware->isDisabled('session'));
    }

    protected function getEncryptedCookieValue(string $key, string $value): string
    {
        $encrypter = $this->container->make(EncrypterContract::class);

        return $encrypter->encrypt(
            CookieValuePrefix::create($key, $encrypter->getKey()) . $value,
            false
        );
    }
}

class EncryptCookiesTestController extends Controller
{
    public function setCookies(): Response
    {
        $response = new Response;
        $response->headers->setCookie(new Cookie('encrypted_cookie', 'value'));
        $response->headers->setCookie(new Cookie('encrypted[array_cookie]', 'value'));
        $response->headers->setCookie(new Cookie('encrypted[nested][array_cookie]', 'value'));
        $response->headers->setCookie(new Cookie('unencrypted_cookie', 'value'));
        $response->headers->setCookie(new Cookie('globally_unencrypted_cookie', 'value'));

        return $response;
    }

    public function queueCookies(): Response
    {
        return new Response;
    }
}

class EncryptCookiesTestMiddleware extends EncryptCookies
{
    protected array $except = [
        'unencrypted_cookie',
    ];
}

class AddQueuedCookiesToResponseTestMiddleware extends AddQueuedCookiesToResponse
{
    public function __construct()
    {
        $cookie = new CookieJar;
        $cookie->queue(new Cookie('encrypted_cookie', 'value'));
        $cookie->queue(new Cookie('encrypted[array_cookie]', 'value'));
        $cookie->queue(new Cookie('encrypted[nested][array_cookie]', 'value'));
        $cookie->queue(new Cookie('unencrypted_cookie', 'value'));
        $cookie->queue(new Cookie('globally_unencrypted_cookie', 'value'));

        $this->cookies = $cookie;
    }
}
