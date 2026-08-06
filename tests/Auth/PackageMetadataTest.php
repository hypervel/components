<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\AuthServiceProvider;
use Hypervel\Auth\Passwords\PasswordResetServiceProvider;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Password;
use Hypervel\Tests\TestCase;
use JsonException;
use ReflectionClass;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Auth dependencies and discovery metadata are declared consistently.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProvidersAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/auth/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ([
            'ext-hash',
            'nesbot/carbon',
            'hypervel/cache',
            'hypervel/collections',
            'hypervel/config',
            'hypervel/console',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/core',
            'hypervel/database',
            'hypervel/hashing',
            'hypervel/http',
            'hypervel/macroable',
            // Auth notifications extend Notification, so this is a runtime dependency.
            'hypervel/notifications',
            'hypervel/queue',
            'hypervel/session',
            'hypervel/support',
            'symfony/console',
            'symfony/http-foundation',
            'symfony/http-kernel',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $providers = [
            AuthServiceProvider::class,
            PasswordResetServiceProvider::class,
        ];

        $this->assertSame($providers, $composer['extra']['hypervel']['providers']);

        foreach ($providers as $provider) {
            $this->assertContains($provider, $rootComposer['extra']['hypervel']['providers']);
        }
    }

    public function testFacadesDocumentEnumIdentifiers(): void
    {
        $authDocblock = (new ReflectionClass(Auth::class))->getDocComment();
        $this->assertIsString($authDocblock);
        $this->assertStringContainsString(
            ' clearUserCache(mixed $identifier, \UnitEnum|string|null $guard = null)',
            $authDocblock
        );

        $passwordDocblock = (new ReflectionClass(Password::class))->getDocComment();
        $this->assertIsString($passwordDocblock);

        foreach ([
            ' broker(\UnitEnum|string|null $name = null)',
            ' resolveBrokerNameForGuard(\UnitEnum|string $guard)',
            ' setDefaultDriver(\UnitEnum|string $name)',
        ] as $method) {
            $this->assertStringContainsString($method, $passwordDocblock);
        }
    }
}
