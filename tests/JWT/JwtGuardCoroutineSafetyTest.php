<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\JWT\ClaimFactory;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Http\Parser\AuthHeaders;
use Hypervel\JWT\Http\Parser\InputSource;
use Hypervel\JWT\Http\Parser\Parser;
use Hypervel\JWT\JwtGuard;
use Hypervel\Testbench\TestCase;
use SensitiveParameter;

use function Hypervel\Coroutine\parallel;

class JwtGuardCoroutineSafetyTest extends TestCase
{
    public function testClaimsAndTtlAreIsolatedBetweenCoroutines(): void
    {
        $manager = new JwtGuardCoroutineManager;
        $guard = $this->createGuard($manager, new JwtGuardCoroutineProvider);

        [$firstToken, $secondToken] = parallel([
            function () use ($guard): string {
                $guard->claims(['tenant' => 'one'])->setTTL(5);
                usleep(1000);

                return $guard->login(new JwtGuardCoroutineUser(1));
            },
            function () use ($guard): string {
                $guard->claims(['tenant' => 'two'])->setTTL(10);
                usleep(1000);

                return $guard->login(new JwtGuardCoroutineUser(2));
            },
        ]);

        $this->assertSame('one', $manager->payloads[$firstToken]['tenant']);
        $this->assertSame(1, $manager->payloads[$firstToken]['sub']);
        $this->assertSame(300, $manager->payloads[$firstToken]['exp'] - $manager->payloads[$firstToken]['iat']);
        $this->assertSame('two', $manager->payloads[$secondToken]['tenant']);
        $this->assertSame(2, $manager->payloads[$secondToken]['sub']);
        $this->assertSame(600, $manager->payloads[$secondToken]['exp'] - $manager->payloads[$secondToken]['iat']);
    }

    public function testTokenAndUserStateAreIsolatedBetweenCoroutines(): void
    {
        $guard = $this->createGuard(new JwtGuardCoroutineManager([
            'token-one' => ['sub' => 1],
            'token-two' => ['sub' => 2],
        ]), new JwtGuardCoroutineProvider);

        [$firstId, $secondId] = parallel([
            function () use ($guard): int|string|null {
                $guard->setToken('token-one');
                usleep(1000);

                return $guard->id();
            },
            function () use ($guard): int|string|null {
                $guard->setToken('token-two');
                usleep(1000);

                return $guard->id();
            },
        ]);

        $this->assertSame(1, $firstId);
        $this->assertSame(2, $secondId);
    }

    public function testResolvedUsersAreIsolatedBetweenCoroutines(): void
    {
        $guard = $this->createGuard(new JwtGuardCoroutineManager([
            'token-one' => ['sub' => 1],
            'token-two' => ['sub' => 2],
        ]), new JwtGuardCoroutineProvider);

        [$firstUserId, $secondUserId] = parallel([
            function () use ($guard): int|string {
                $guard->setToken('token-one');
                usleep(1000);

                return $guard->user()->getAuthIdentifier();
            },
            function () use ($guard): int|string {
                $guard->setToken('token-two');
                usleep(1000);

                return $guard->user()->getAuthIdentifier();
            },
        ]);

        $this->assertSame(1, $firstUserId);
        $this->assertSame(2, $secondUserId);
    }

    /**
     * Create a JwtGuard instance.
     */
    private function createGuard(ManagerContract $manager, UserProvider $provider): JwtGuard
    {
        return new JwtGuard(
            'jwt',
            $provider,
            $manager,
            new ClaimFactory(new Repository([
                'jwt' => [
                    'issuer' => null,
                    'lock_subject' => false,
                ],
            ])),
            new Parser([new AuthHeaders, new InputSource]),
            $this->app,
        );
    }
}

class JwtGuardCoroutineManager implements ManagerContract
{
    public array $payloads = [];

    public function __construct(
        private readonly array $decodedPayloads = [],
    ) {
    }

    public function encode(array $payload): string
    {
        $token = 'token-' . $payload['sub'];
        $this->payloads[$token] = $payload;

        return $token;
    }

    public function decode(string $token, bool $validate = true, bool $checkBlacklist = true): array
    {
        return $this->decodedPayloads[$token];
    }

    public function refresh(
        string $token,
        bool $forceForever = false,
        bool $resetClaims = false,
        array $customClaims = [],
        int|false|null $ttl = false,
    ): string {
        return $token;
    }

    public function invalidate(string $token, bool $forceForever = false): bool
    {
        return true;
    }

    public function hasBlacklistEnabled(): bool
    {
        return false;
    }
}

class JwtGuardCoroutineUser implements Authenticatable
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

class JwtGuardCoroutineProvider implements UserProvider
{
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        return new JwtGuardCoroutineUser($identifier);
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
