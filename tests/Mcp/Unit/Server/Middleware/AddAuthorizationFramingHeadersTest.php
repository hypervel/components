<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server\Middleware;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Mcp\Server\Middleware\AddAuthorizationFramingHeaders;
use Hypervel\Routing\Route;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class AddAuthorizationFramingHeadersTest extends TestCase
{
    #[TestWith(['passport.authorizations.authorize'])]
    #[TestWith(['passport.authorizations.approve'])]
    #[TestWith(['passport.authorizations.deny'])]
    #[TestWith(['passport.device'])]
    #[TestWith(['passport.device.authorizations.authorize'])]
    #[TestWith(['passport.device.authorizations.approve'])]
    #[TestWith(['passport.device.authorizations.deny'])]
    public function testDeniesFramingOfTheAuthorizationPages(string $name): void
    {
        $request = Request::create('/oauth/authorize');
        $request->setRouteResolver(fn (): Route => (new Route('GET', '/oauth/authorize', []))->name($name));

        $response = (new AddAuthorizationFramingHeaders)->handle($request, fn (): Response => response('consent'));

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function testReplacesAWeakerFrameOptionsHeader(): void
    {
        $request = Request::create('/oauth/authorize');
        $request->setRouteResolver(fn (): Route => (new Route('GET', '/oauth/authorize', []))->name('passport.authorizations.authorize'));

        $response = (new AddAuthorizationFramingHeaders)->handle($request, fn (): Response => response('consent', 200, [
            'X-Frame-Options' => 'SAMEORIGIN',
        ]));

        $this->assertSame(['DENY'], $response->headers->all('x-frame-options'));
    }

    public function testKeepsTheContentSecurityPolicyTheApplicationAlreadySet(): void
    {
        $request = Request::create('/oauth/authorize');
        $request->setRouteResolver(fn (): Route => (new Route('GET', '/oauth/authorize', []))->name('passport.authorizations.authorize'));

        $response = (new AddAuthorizationFramingHeaders)->handle($request, fn (): Response => response('consent', 200, [
            'Content-Security-Policy' => "default-src 'self'",
        ]));

        $this->assertSame(["default-src 'self'", "frame-ancestors 'none'"], $response->headers->all('content-security-policy'));
    }

    public function testLeavesOtherResponsesUntouched(): void
    {
        $request = Request::create('/mcp');
        $request->setRouteResolver(fn (): Route => (new Route('POST', '/mcp', []))->name('mcp'));

        $response = (new AddAuthorizationFramingHeaders)->handle($request, fn (): Response => response('ok'));

        $this->assertFalse($response->headers->has('X-Frame-Options'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }
}
