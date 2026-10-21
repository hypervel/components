<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Di\ClassMap\ClassMapManager;
use Hypervel\Di\Exceptions\InvalidDefinitionException;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Foundation\Fixtures\AspectRegistrationTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class AspectRegistrationTest extends TestCase
{
    protected array $providers = [InnerRegistrationProvider::class, OuterRegistrationProvider::class];

    /**
     * Register the providers used by this application bootstrap.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return $this->providers;
    }

    #[DataProvider('applicationRestarts')]
    public function testApplicationRestartsReopenAndResealRegistration(string $restart): void
    {
        $firstApplication = $this->app;
        $this->assertSame('outer(inner(value))', (new AspectRegistrationTarget)->value());

        $this->providers = array_reverse($this->providers);
        $this->{$restart}();

        $this->assertNotSame($firstApplication, $this->app);
        $this->assertSame('outer(inner(value))', (new AspectRegistrationTarget)->value());
        $this->assertSame([
            RegistrationReplacement::class => __FILE__,
        ], ClassMapManager::getEntries());

        try {
            AspectCollector::register(OuterRegistrationAspect::class);
            $this->fail('A fresh application must close aspect registration again.');
        } catch (InvalidDefinitionException $exception) {
            $this->assertStringContainsString('register()', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('after proxy generation');
        ClassMapManager::add([RegistrationReplacement::class => __FILE__]);
    }

    /**
     * Provide the supported ways to recreate the test application.
     */
    public static function applicationRestarts(): array
    {
        return [['reloadApplication'], ['refreshApplication']];
    }
}

class OuterRegistrationProvider extends ServiceProvider
{
    /**
     * Register the outer aspect and a repeatable class map entry.
     */
    public function register(): void
    {
        $this->aspects(OuterRegistrationAspect::class);
        $this->classMap([RegistrationReplacement::class => __FILE__]);
    }
}

class InnerRegistrationProvider extends ServiceProvider
{
    /**
     * Register the inner aspect.
     */
    public function register(): void
    {
        $this->aspects(InnerRegistrationAspect::class);
    }
}

class OuterRegistrationAspect extends AbstractAspect
{
    public array $classes = [AspectRegistrationTarget::class . '::value'];

    public ?int $priority = 20;

    /**
     * Wrap the target result with the outer marker.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return 'outer(' . $proceedingJoinPoint->process() . ')';
    }
}

class InnerRegistrationAspect extends AbstractAspect
{
    public array $classes = [AspectRegistrationTarget::class . '::value'];

    public ?int $priority = 10;

    /**
     * Wrap the target result with the inner marker.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return 'inner(' . $proceedingJoinPoint->process() . ')';
    }
}

class RegistrationReplacement
{
}
