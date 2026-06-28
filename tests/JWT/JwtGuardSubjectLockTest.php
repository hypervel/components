<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hypervel\Config\Repository;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Http\Request;
use Hypervel\JWT\ClaimFactory;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Http\Parser\AuthHeaders;
use Hypervel\JWT\Http\Parser\InputSource;
use Hypervel\JWT\Http\Parser\Parser;
use Hypervel\JWT\JwtGuard;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use SensitiveParameter;

class JwtGuardSubjectLockTest extends TestCase
{
    public function testMatchingProviderSubjectResolvesUser(): void
    {
        $user = new JwtGuardSubjectUser(42);
        $provider = new JwtGuardSubjectModelProvider(JwtGuardSubjectUser::class, $user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->once()->andReturn([
            'sub' => 42,
            'prv' => hash('xxh128', JwtGuardSubjectUser::class),
        ]);

        $guard = $this->createGuard($provider, $jwtManager);

        $this->assertSame($user, $guard->user());
        $this->assertSame(1, $provider->retrieveByIdCalls);
    }

    public function testMismatchedProviderSubjectDoesNotResolveUser(): void
    {
        $provider = new JwtGuardSubjectModelProvider(JwtGuardSubjectUser::class, new JwtGuardSubjectUser(42));

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->once()->andReturn([
            'sub' => 42,
            'prv' => hash('xxh128', JwtGuardOtherSubjectUser::class),
        ]);

        $guard = $this->createGuard($provider, $jwtManager);

        $this->assertNull($guard->user());
        $this->assertSame(0, $provider->retrieveByIdCalls);
    }

    public function testMissingProviderSubjectDoesNotResolveUser(): void
    {
        $provider = new JwtGuardSubjectModelProvider(JwtGuardSubjectUser::class, new JwtGuardSubjectUser(42));

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->once()->andReturn(['sub' => 42]);

        $guard = $this->createGuard($provider, $jwtManager);

        $this->assertNull($guard->user());
        $this->assertSame(0, $provider->retrieveByIdCalls);
    }

    public function testGetUserIdRejectsMismatchedProviderSubject(): void
    {
        $provider = new JwtGuardSubjectModelProvider(JwtGuardSubjectUser::class, new JwtGuardSubjectUser(42));

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->once()->andReturn([
            'sub' => 42,
            'prv' => hash('xxh128', JwtGuardOtherSubjectUser::class),
        ]);

        $guard = $this->createGuard($provider, $jwtManager);

        $this->assertNull($guard->getUserId());
        $this->assertSame(0, $provider->retrieveByIdCalls);
    }

    public function testProviderWithoutModelSkipsSubjectLocking(): void
    {
        $user = new JwtGuardSubjectUser(42);
        $provider = new JwtGuardSubjectProvider($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->once()->andReturn(['sub' => 42]);

        $guard = $this->createGuard($provider, $jwtManager);

        $this->assertSame($user, $guard->user());
        $this->assertSame(1, $provider->retrieveByIdCalls);
    }

    /**
     * Create a JwtGuard instance.
     */
    private function createGuard(UserProvider $provider, ManagerContract $jwtManager): JwtGuard
    {
        RequestContext::set(Request::create('/', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer token',
        ]));

        return new JwtGuard(
            'jwt',
            $provider,
            $jwtManager,
            new ClaimFactory(new Repository([
                'jwt' => [
                    'issuer' => null,
                    'lock_subject' => true,
                ],
            ])),
            new Parser([new AuthHeaders, new InputSource]),
            $this->app,
        );
    }
}

class JwtGuardSubjectUser implements Authenticatable
{
    public function __construct(
        private readonly int|string $id,
    ) {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(string $value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}

class JwtGuardOtherSubjectUser extends JwtGuardSubjectUser
{
}

class JwtGuardSubjectProvider implements UserProvider
{
    public int $retrieveByIdCalls = 0;

    public function __construct(
        protected readonly ?Authenticatable $user,
    ) {
    }

    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        ++$this->retrieveByIdCalls;

        return $this->user;
    }

    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] string $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] string $token): void
    {
    }

    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[SensitiveParameter]
        array $credentials,
        bool $force = false,
    ): void {
    }
}

class JwtGuardSubjectModelProvider extends JwtGuardSubjectProvider
{
    public function __construct(
        private readonly string $model,
        ?Authenticatable $user,
    ) {
        parent::__construct($user);
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
