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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class UserContextResolverTest extends TestCase
{
    use VerifiesDoubles;

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testCustomResolverRethrowsCoroutineCancellation(bool $wrapped): void
    {
        $cancellation = $wrapped ? new RuntimeException('Resolver cleanup failed.', previous: new CanceledException) : new CanceledException;
        $user = Double::for(Authenticatable::class)->strict();
        $guard = Double::for(Guard::class)->strict();
        $guard->expects('hasUser')->returns(true);
        $guard->expects('user')->returns($user);
        $auth = Double::for(Factory::class)->strict();
        $auth->expects('guard')->returns($guard);
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('userResolver')->returns(
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
