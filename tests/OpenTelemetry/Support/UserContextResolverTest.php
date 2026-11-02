<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Http\Request;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\RequestTelemetryState;
use Hypervel\OpenTelemetry\Support\UserContextResolver;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class UserContextResolverTest extends TestCase
{
    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testCustomResolverRethrowsCoroutineCancellation(bool $wrapped): void
    {
        $cancellation = $wrapped ? new RuntimeException('Resolver cleanup failed.', previous: new CanceledException) : new CanceledException;
        $user = m::mock(Authenticatable::class);
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('hasUser')->once()->andReturnTrue();
        $guard->shouldReceive('user')->once()->andReturn($user);
        $auth = m::mock(Factory::class);
        $auth->shouldReceive('guard')->once()->andReturn($guard);
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('userResolver')->once()->andReturn(
            static fn (): never => throw $cancellation,
        );
        $resolver = new UserContextResolver($auth, $manager);
        $state = new RequestTelemetryState(
            Request::create('/'),
            0,
            null,
            null,
            null,
            null,
            [],
            false,
        );

        $caught = null;

        try {
            $resolver->resolve($state);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
    }
}
