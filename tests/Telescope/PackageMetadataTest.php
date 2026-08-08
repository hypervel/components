<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hypervel\Telescope\TelescopeServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure split-package dependencies match the monorepo constraints.
     *
     * @throws JsonException
     */
    public function testDirectDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/telescope/composer.json'),
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

        $hypervelDependencies = [
            'hypervel/auth',
            'hypervel/broadcasting',
            'hypervel/bus',
            'hypervel/cache',
            'hypervel/collections',
            'hypervel/console',
            'hypervel/container',
            'hypervel/context',
            'hypervel/contracts',
            'hypervel/coroutine',
            'hypervel/database',
            'hypervel/di',
            'hypervel/events',
            'hypervel/foundation',
            'hypervel/http',
            'hypervel/http-server',
            'hypervel/log',
            'hypervel/mail',
            'hypervel/notifications',
            'hypervel/queue',
            'hypervel/redis',
            'hypervel/support',
            'hypervel/view',
        ];
        $externalDependencies = [
            'ext-json',
            'ext-mbstring',
            'ext-pdo',
            'guzzlehttp/guzzle',
            'nesbot/carbon',
            'psr/http-message',
            'psr/log',
            'symfony/console',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'symfony/mime',
            'symfony/var-dumper',
        ];

        $this->assertSame('^8.4', $composer['require']['php']);

        foreach ($hypervelDependencies as $dependency) {
            $this->assertSame('^0.4', $composer['require'][$dependency]);
            $this->assertSame('self.version', $rootComposer['replace'][$dependency]);
        }

        foreach ($externalDependencies as $dependency) {
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $this->assertArrayNotHasKey('hypervel/server', $composer['require']);
        $this->assertSame(
            [TelescopeServiceProvider::class],
            $composer['extra']['hypervel']['providers'],
        );
        $this->assertContains(
            TelescopeServiceProvider::class,
            $rootComposer['extra']['hypervel']['providers'],
        );
    }
}
