<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Testbench\Attributes\WithConfig;
use RuntimeException;

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

    public function testPasswordBrokerIsDerivedFromCurrentGuardProvider(): void
    {
        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);

        $auth->shouldUse('web');
        $this->assertSame('users', Fortify::passwordBrokerName());

        $auth->shouldUse('admin');
        $this->assertSame('admins', Fortify::passwordBrokerName());
    }

    #[WithConfig('auth.passwords.duplicate-users', ['provider' => 'users', 'table' => 'other_password_reset_tokens'])]
    public function testPasswordBrokerInferenceFailsWhenProviderMappingIsAmbiguous(): void
    {
        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);
        $auth->shouldUse('web');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to infer a password broker for auth guard [web].');

        Fortify::passwordBrokerName();
    }
}
