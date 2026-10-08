<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel;

use Hypervel\Notifications\SlackChannelServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Slack dependencies, autoloading, and discovery metadata are declared.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/slack-notification-channel/composer.json'),
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

        $this->assertSame('*', $composer['require']['ext-mbstring']);

        foreach ([
            'guzzlehttp/guzzle',
            'hypervel/collections',
            'hypervel/conditionable',
            'hypervel/contracts',
            'hypervel/http',
            'hypervel/notifications',
            'hypervel/support',
            'psr/http-message',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertSame(['Hypervel\Notifications\\' => 'src/'], $composer['autoload']['psr-4']);
        $this->assertContains('src/slack-notification-channel/src/', $rootComposer['autoload']['psr-4']['Hypervel\Notifications\\']);
        $this->assertSame([SlackChannelServiceProvider::class], $composer['extra']['hypervel']['providers']);
    }
}
