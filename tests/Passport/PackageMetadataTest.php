<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Passport dependencies and discovery metadata are declared consistently.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/passport/composer.json'),
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

        foreach ([
            'ext-openssl',
            'defuse/php-encryption',
            'firebase/php-jwt',
            'hypervel/cache',
            'hypervel/cookie',
            'hypervel/database',
            'hypervel/foundation',
            'hypervel/session',
            'lcobucci/jwt',
            'league/oauth2-server',
            'nyholm/psr7',
            'psr/clock',
            'psr/http-message',
            'symfony/http-foundation',
            'symfony/psr-http-message-bridge',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $providers = [PassportServiceProvider::class];

        $this->assertSame($providers, $composer['extra']['hypervel']['providers']);
        $this->assertContains(PassportServiceProvider::class, $rootComposer['extra']['hypervel']['providers']);
        $this->assertSame('self.version', $rootComposer['replace']['hypervel/passport']);
        $this->assertSame('src/', $composer['autoload']['psr-4']['Hypervel\Passport\\']);
        $this->assertSame('database/factories/', $composer['autoload']['psr-4']['Hypervel\Passport\Database\Factories\\']);
        $this->assertSame('src/passport/src/', $rootComposer['autoload']['psr-4']['Hypervel\Passport\\']);
        $this->assertSame('src/passport/database/factories/', $rootComposer['autoload']['psr-4']['Hypervel\Passport\Database\Factories\\']);
    }
}
