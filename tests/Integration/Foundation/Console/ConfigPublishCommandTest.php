<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;

/**
 * @internal
 * @coversNothing
 */
class ConfigPublishCommandTest extends \Hypervel\Testbench\TestCase
{
    use InteractsWithPublishedFiles;

    public function testItCanListPublishableConfigFiles()
    {
        // The publishesConfig() calls from service providers should make
        // config files discoverable via pathsToPublish(null, 'config')
        $paths = ServiceProvider::pathsToPublish(null, 'config');

        $this->assertNotEmpty($paths, 'No config files registered for publishing under the "config" group.');
    }

    public function testItCanPublishSpecificConfigFile()
    {
        $paths = ServiceProvider::pathsToPublish(null, 'config');

        if (empty($paths)) {
            $this->markTestSkipped('No config files registered for publishing.');
        }

        // Get the first config file name
        $firstSource = array_key_first($paths);
        $name = basename($firstSource, '.php');
        $destination = $this->app->configPath("{$name}.php");
        $originalContents = is_file($destination)
            ? file_get_contents($destination)
            : null;

        // config:publish overwrites a real runtime config file. Restore it so
        // later tests in the same process don't boot with mutated app config.
        $this->beforeApplicationDestroyed(function () use ($destination, $originalContents) {
            if ($originalContents === null) {
                @unlink($destination);

                return;
            }

            file_put_contents($destination, $originalContents);
        });

        $this->artisan('config:publish', ['name' => $name, '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain("Published '{$name}' configuration file.");

        $this->assertFilenameExists("config/{$name}.php");
    }

    public function testItFailsWithUnrecognizedConfigFile()
    {
        $this->artisan('config:publish', ['name' => 'nonexistent-config-file'])
            ->expectsOutputToContain('Unrecognized configuration file.')
            ->assertExitCode(1);
    }

    public function testItReportsEmptyWhenNoConfigFilesRegistered()
    {
        // Flush all publish state to simulate no registered config files
        $originalPublishes = ServiceProvider::$publishes;
        $originalGroups = ServiceProvider::$publishGroups;

        ServiceProvider::flushState();

        $this->artisan('config:publish', ['name' => 'anything'])
            ->expectsOutputToContain('No publishable configuration files found.')
            ->assertExitCode(0);

        // Restore
        ServiceProvider::$publishes = $originalPublishes;
        ServiceProvider::$publishGroups = $originalGroups;
    }
}
