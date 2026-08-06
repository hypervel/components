<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\BroadcastServiceProvider;
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

        $this->assertSame('^0.4', $composer['require']['hypervel/routing']);
        $this->assertSame('^3.0', $composer['require']['psr/log']);
        $this->assertArrayNotHasKey('hypervel/auth', $composer['require']);
        $this->assertArrayNotHasKey('hypervel/cache', $composer['require']);

        $this->assertSame(
            'Required to use the Redis broadcast driver (^0.4).',
            $composer['suggest']['hypervel/redis']
        );
        $this->assertSame(
            'Required to use the Ably broadcast driver (^1.0).',
            $composer['suggest']['ably/ably-php']
        );
        $this->assertSame(
            'Required to use the Pusher broadcast driver (^7.2).',
            $composer['suggest']['pusher/pusher-php-server']
        );

        $providers = [BroadcastServiceProvider::class];

        $this->assertSame($providers, $composer['extra']['hypervel']['providers']);
        $this->assertContains(BroadcastServiceProvider::class, $rootComposer['extra']['hypervel']['providers']);
    }
}
