<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use App\User;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\TokenGuard;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\PHPStan\ReturnTypes\AuthGuardHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Tests\PHPStan\Fixtures\Auth\CreatingAuthManager;
use Hypervel\Tests\PHPStan\Fixtures\Auth\ProvidingAuthManager;
use Hypervel\Tests\PHPStan\Fixtures\Auth\SelectingAuthManager;
use LogicException;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthGuardHelperTest extends PHPStanTestCase
{
    /**
     * @param class-string<AuthManager> $managerClass
     * @param list<class-string> $userModels
     */
    #[DataProvider('managerProvider')]
    public function testTypesGuardsAndUsersOnlyThroughTheStockSelection(string $managerClass, ?string $guardType, array $userModels): void
    {
        $app = new Container;
        $app->instance('config', new Repository(['auth' => [
            'guards' => [
                'web' => ['driver' => 'session', 'provider' => 'users'],
                'api' => ['driver' => 'custom', 'provider' => 'users'],
            ],
            'providers' => [
                'users' => ['driver' => 'eloquent', 'model' => User::class],
            ],
        ]]));

        $manager = new $managerClass($app);
        $manager->extend('custom', static fn (): TokenGuard => throw new LogicException('Analysis never creates guards.'));
        $app->instance('auth', $manager);

        $reflectionProvider = $this->createReflectionProvider();
        $helper = new AuthGuardHelper($reflectionProvider, self::getContainer()->getByType(ClosureTypeFactory::class), self::getContainer()->getByType(RuntimeInputs::class));
        $helper->setContainer($app);

        $dependent = $reflectionProvider->getClass(AuthManager::class);

        $this->assertSame($guardType, $helper->getDefaultGuardType($dependent)?->describe(VerbosityLevel::precise()));
        $this->assertSame($userModels, $helper->getUserModels($dependent));
    }

    /** @return iterable<string, array{class-string<AuthManager>, ?string, list<class-string>}> */
    public static function managerProvider(): iterable
    {
        yield 'stock manager' => [AuthManager::class, 'Hypervel\Auth\SessionGuard|Hypervel\Auth\TokenGuard', [User::class]];

        // A manager whose guard() is overridden may give a name another guard.
        yield 'overridden guard()' => [SelectingAuthManager::class, null, []];

        // An overridden callCustomCreator() creates the custom drivers' guards instead of their creators.
        yield 'overridden callCustomCreator()' => [CreatingAuthManager::class, 'Hypervel\Auth\RequestGuard|Hypervel\Auth\SessionGuard', [User::class]];

        // A manager whose createUserProvider() is overridden may give a guard another provider's users.
        yield 'overridden createUserProvider()' => [ProvidingAuthManager::class, 'Hypervel\Auth\SessionGuard|Hypervel\Auth\TokenGuard', []];
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
