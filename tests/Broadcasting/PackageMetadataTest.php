<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\BroadcastServiceProvider;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Broadcasting dependencies are declared consistently.
     *
     * @throws JsonException
     */
    public function testDependenciesAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/broadcasting/composer.json'),
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

        $internalConstraint = '^' . Str::before(
            $composer['extra']['branch-alias']['dev-main'],
            '-dev',
        );

        $this->assertSame($internalConstraint, $composer['require']['hypervel/routing']);

        foreach (['guzzlehttp/guzzle', 'psr/log', 'symfony/http-kernel'] as $dependency) {
            $this->assertArrayHasKey($dependency, $rootComposer['require']);
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertSame($rootComposer['require'][$dependency], $composer['require'][$dependency]);
        }

        $this->assertArrayNotHasKey('hypervel/auth', $composer['require']);
        $this->assertArrayNotHasKey('hypervel/cache', $composer['require']);

        foreach (['hypervel/redis', 'ably/ably-php', 'pusher/pusher-php-server'] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['suggest']);
            $this->assertIsString($composer['suggest'][$dependency]);
            $this->assertNotSame('', trim($composer['suggest'][$dependency]));
        }

        $providers = [BroadcastServiceProvider::class];

        $this->assertSame($providers, $composer['extra']['hypervel']['providers']);
        $this->assertContains(BroadcastServiceProvider::class, $rootComposer['extra']['hypervel']['providers']);
    }
}
