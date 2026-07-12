<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Password;
use Hypervel\Testbench\Attributes\WithConfig;
use InvalidArgumentException;

class FortifyStaticStateTest extends TestCase
{
    public function testRedirectCallbackIsEvaluatedPerRequestAndResetByFlushState(): void
    {
        Fortify::redirectUsing('login', static function (Request $request): string {
            return $request->headers->get('X-Admin') === '1' ? '/dashboard' : '/account';
        });

        $this->assertSame('/dashboard', Fortify::redirects('login', request: Request::create('/', server: ['HTTP_X_ADMIN' => '1'])));
        $this->assertSame('/account', Fortify::redirects('login', request: Request::create('/')));

        Fortify::flushState();

        $this->assertSame('/home', Fortify::redirects('login', request: Request::create('/')));
    }

    public function testPasswordBrokerFollowsGuardDeclaration(): void
    {
        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);

        $auth->shouldUse('web');
        $this->assertSame('users', Password::getDefaultDriver());

        $auth->shouldUse('admin');
        $this->assertSame('admins', Password::getDefaultDriver());
    }

    #[WithConfig('auth.guards.unkeyed', ['driver' => 'session', 'provider' => 'users'])]
    public function testPasswordBrokerThrowsWhenGuardDeclaresNone(): void
    {
        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);
        $auth->shouldUse('unkeyed');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth guard [unkeyed] does not declare a passwords broker. Set auth.guards.unkeyed.passwords.');

        Password::broker();
    }
}
