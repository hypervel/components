<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hypervel\Http\Request;
use Hypervel\Socialite\Two\BitbucketProvider;
use Hypervel\Socialite\Two\GithubProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class EmailLookupTest extends TestCase
{
    #[DataProvider('emailFailures')]
    public function testOnlyOrdinaryEmailFailuresAllowAuthenticationToContinue(string $providerClass, string $kind): void
    {
        $failure = match ($kind) {
            'direct' => new CanceledException,
            'wrapped' => new RuntimeException('HTTP cleanup failed.', previous: new CanceledException),
            default => new RuntimeException('Email lookup failed.'),
        };
        $provider = new $providerClass(Request::create('/'), 'client', 'secret', 'redirect');
        $http = m::mock(Client::class);
        $provider->setHttpClient($http);
        $userData = $providerClass === GithubProvider::class
            ? ['id' => 'user-id', 'node_id' => 'node-id', 'login' => 'taylor', 'avatar_url' => 'https://example.com/avatar']
            : ['uuid' => 'user-id', 'username' => 'taylor'];
        $http->shouldReceive('get')->once()->ordered()->andReturn(new Response(body: json_encode($userData)));
        $http->shouldReceive('get')->once()->ordered()->andThrow($failure);
        $caught = null;
        $user = null;

        try {
            $user = $provider->userFromToken('token');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        if ($kind === 'ordinary') {
            $this->assertNull($caught);
            $this->assertSame('user-id', $user->getId());
            $this->assertNull($user->getEmail());
        } else {
            $this->assertSame($failure, $caught);
            $this->assertNull($user);
        }
    }

    /**
     * Provide cancellation and ordinary failures for both optional email lookups.
     */
    public static function emailFailures(): array
    {
        return [
            'GitHub ordinary' => [GithubProvider::class, 'ordinary'],
            'GitHub direct cancellation' => [GithubProvider::class, 'direct'],
            'GitHub wrapped cancellation' => [GithubProvider::class, 'wrapped'],
            'Bitbucket ordinary' => [BitbucketProvider::class, 'ordinary'],
            'Bitbucket direct cancellation' => [BitbucketProvider::class, 'direct'],
            'Bitbucket wrapped cancellation' => [BitbucketProvider::class, 'wrapped'],
        ];
    }
}
