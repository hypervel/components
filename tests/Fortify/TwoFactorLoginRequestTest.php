<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Closure;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Hypervel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Session\Store;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Fortify\Fixtures\Admin;
use Hypervel\Tests\Fortify\Fixtures\UserWithTwoFactor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NullSessionHandler;

class TwoFactorLoginRequestTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Create fixture tables after refreshing the database.
     */
    protected function afterRefreshingDatabase(): void
    {
        $this->createAuthenticatableTable('users');
        $this->createAuthenticatableTable('admins');
    }

    public function testChallengedUserMustUseTheGuardThatStartedTheChallenge(): void
    {
        UserWithTwoFactor::forceCreate([
            'id' => 1,
            'email' => 'user@example.test',
        ]);

        Admin::forceCreate([
            'id' => 1,
            'email' => 'admin@example.test',
        ]);

        $request = TwoFactorLoginRequest::create('/two-factor-challenge', 'POST');
        $request->setHypervelSession($session = new Store('fortify', new NullSessionHandler));

        $session->put([
            'login.id' => 1,
            'login.guard' => 'admin',
            'login.remember' => true,
        ]);

        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);

        $auth->shouldUse('web');
        $this->assertFalse($request->hasChallengedUser());

        $auth->shouldUse('admin');
        $this->assertTrue($request->hasChallengedUser());
        $this->assertInstanceOf(Admin::class, $request->challengedUser());
    }

    public function testTwoFactorChallengeStoresPrimaryKeyWhenAuthIdentifierDiffers(): void
    {
        config(['auth.providers.users.model' => AlternateIdentifierUser::class]);

        $this->createAuthenticatableTable('alternate_identifier_users');

        $user = AlternateIdentifierUser::forceCreate([
            'id' => 42,
            'email' => 'alternate@example.test',
        ]);

        $request = Request::create('/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setHypervelSession($session = new Store('fortify', new NullSessionHandler));

        $action = new TwoFactorChallengeWriter(
            $this->app->make(LoginRateLimiter::class),
            $this->app->make(Config::class),
        );

        $action->challenge($request, $user);

        $this->assertSame(42, $session->get('login.id'));

        $challengeRequest = TwoFactorLoginRequest::create('/two-factor-challenge', 'POST');
        $challengeRequest->setHypervelSession($session);

        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);
        $auth->shouldUse('web');

        $this->assertTrue($challengeRequest->hasChallengedUser());
        $this->assertTrue($challengeRequest->challengedUser()->is($user));
    }

    /**
     * Create a minimal authenticatable fixture table.
     */
    private function createAuthenticatableTable(string $table): void
    {
        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }
}

class AlternateIdentifierUser extends UserWithTwoFactor
{
    protected ?string $table = 'alternate_identifier_users';

    public function getAuthIdentifierName(): string
    {
        return 'email';
    }
}

class TwoFactorChallengeWriter extends RedirectIfTwoFactorAuthenticatable
{
    public function challenge(Request $request, Authenticatable&Model $user): Response
    {
        return $this->twoFactorChallengeResponse($request, $user);
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
