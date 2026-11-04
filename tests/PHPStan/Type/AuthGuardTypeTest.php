<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type;

use App\User;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\RequestGuard;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\PHPStan\ReturnTypes\AuthExtension;
use Hypervel\PHPStan\ReturnTypes\AuthGuardHelper;
use Hypervel\PHPStan\ReturnTypes\GuardExtension;
use LogicException;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthGuardTypeTest extends TypeInferenceTestCase
{
    #[DataProvider('dataFileAsserts')]
    public function testFileAsserts(
        string $assertType,
        string $file,
        mixed ...$args,
    ): void {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    /**
     * Get the fixtures' type assertions, analyzed against this test's own auth setup.
     *
     * @return iterable<mixed>
     */
    public static function dataFileAsserts(): iterable
    {
        $config = new Repository(['auth' => [
            'guards' => [
                'web' => ['driver' => 'session', 'provider' => 'users'],
                'legacy' => ['driver' => 'session', 'provider' => 'database'],
                'api' => ['driver' => 'token', 'provider' => 'database'],
                'members' => ['driver' => 'request', 'provider' => 'users'],
                'request' => ['driver' => 'request', 'provider' => 'database'],
                'remote' => ['driver' => 'session', 'provider' => 'remote'],
                'missing' => ['driver' => 'session', 'provider' => 'absent'],
                'providerless' => ['driver' => 'request'],
                '0' => ['driver' => 'session', 'provider' => 'users'],
            ],
            'providers' => [
                'users' => ['driver' => 'eloquent', 'model' => User::class],
                'database' => ['driver' => 'database', 'table' => 'users'],
                'remote' => ['driver' => 'remote'],
            ],
        ]]);

        $app = new Container;
        $app->instance('config', $config);

        $manager = new AuthManager($app);
        $manager->viaRequest('request', static fn () => null);
        $manager->extend('token', static fn (): RequestGuard => throw new LogicException('Analysis never creates guards.'));
        $manager->extend('untyped', static fn () => throw new LogicException('Analysis never creates guards.'));
        $manager->provider('remote', static fn () => throw new LogicException('Analysis never creates providers.'));
        $app->instance('auth', $manager);

        // These services read the application's auth setup. This test's own config file gives
        // it its own service instances, so their swapped container and cached guard types
        // never reach other tests.
        foreach ([AuthGuardHelper::class, GuardExtension::class, AuthExtension::class] as $service) {
            self::getContainer()->getByType($service)->setContainer($app);
        }

        yield from self::gatherAssertTypes(__DIR__ . '/data/auth-guards.php');

        // Any guard the analyzer cannot type leaves the default guard with its declared type.
        $config->set('auth.guards.untyped', ['driver' => 'untyped']);

        yield from self::gatherAssertTypes(__DIR__ . '/data/auth-untyped-guard.php');
    }

    /**
     * Get the analyzer configuration files for this test.
     *
     * @return string[]
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-auth-guards.neon'];
    }
}
