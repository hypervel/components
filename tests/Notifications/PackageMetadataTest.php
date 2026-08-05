<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Notifications\NotificationServiceProvider;
use Hypervel\Tests\TestCase;
use JsonException;

class PackageMetadataTest extends TestCase
{
    /**
     * Ensure Notifications dependencies and discovery metadata are declared consistently.
     *
     * @throws JsonException
     */
    public function testDependenciesAndProviderAreDeclared(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../../src/notifications/composer.json'),
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

        foreach ($composer['require'] as $package => $constraint) {
            if ($package === 'php') {
                continue;
            }

            if (str_starts_with($package, 'hypervel/')) {
                $this->assertSame('self.version', $rootComposer['replace'][$package]);
            } else {
                $this->assertSame($constraint, $rootComposer['require'][$package]);
            }
        }

        $this->assertSame('*', $composer['require']['ext-mbstring']);

        foreach (['symfony/console', 'hypervel/conditionable', 'hypervel/macroable'] as $dependency) {
            $this->assertArrayHasKey($dependency, $composer['require']);
            $this->assertIsString($composer['require'][$dependency]);
            $this->assertNotSame('', trim($composer['require'][$dependency]));
        }

        $this->assertArrayNotHasKey('hypervel/filesystem', $composer['require']);
        $this->assertArrayNotHasKey('hypervel/object-pool', $composer['require']);

        $providers = [NotificationServiceProvider::class];

        $this->assertSame($providers, $composer['extra']['hypervel']['providers']);
        $this->assertContains(NotificationServiceProvider::class, $rootComposer['extra']['hypervel']['providers']);
    }
}
