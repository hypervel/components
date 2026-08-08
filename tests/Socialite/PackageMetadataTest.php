<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use Hypervel\Socialite\Socialite;
use Hypervel\Tests\TestCase;
use JsonException;
use ReflectionClass;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Socialite's direct runtime dependencies match its implementation.
     *
     * @throws JsonException
     */
    public function testRuntimeDependenciesAreDeclaredWithoutTheRemovedRsaLibrary(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/socialite/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rootComposer = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('firebase/php-jwt', $composer['require']);
        $this->assertArrayHasKey('firebase/php-jwt', $rootComposer['require']);
        $this->assertSame(
            $rootComposer['require']['firebase/php-jwt'],
            $composer['require']['firebase/php-jwt'],
        );
        $this->assertArrayNotHasKey('phpseclib/phpseclib', $composer['require']);
        $this->assertArrayNotHasKey('phpseclib/phpseclib', $rootComposer['require']);
    }

    public function testFacadeDocumentsOnlyTheManagerSurface(): void
    {
        $docblock = (new ReflectionClass(Socialite::class))->getDocComment();
        $this->assertIsString($docblock);

        foreach (['with', 'driver', 'buildOAuth2Provider', 'extend', 'forgetDrivers'] as $method) {
            $this->assertStringContainsString(" {$method}(", $docblock);
        }

        foreach (['formatConfig', 'redirect', 'user', 'userFromToken', 'refreshToken'] as $method) {
            $this->assertStringNotContainsString(" {$method}(", $docblock);
        }
    }
}
