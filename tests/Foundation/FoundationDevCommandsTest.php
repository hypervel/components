<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Composer\InstalledVersions;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\DevCommand;
use Hypervel\Foundation\DevCommandColor;
use Hypervel\Foundation\DevCommands;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class FoundationDevCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DevCommands::flushState();

        $app = new Application(__DIR__);
        $app['env'] = 'testing';
        $app->setRunningInConsole(true);
    }

    public function testRegisterAddsCommand(): void
    {
        $devCommand = DevCommands::register('echo hello', 'greeter');

        $this->assertInstanceOf(DevCommand::class, $devCommand);

        $commands = DevCommands::commands();

        $this->assertCount(1, $commands);
        $this->assertSame('echo hello', $commands[0]['command']);
        $this->assertSame('greeter', $commands[0]['name']);
    }

    public function testRegisterDerivesNameFromCommand(): void
    {
        DevCommands::register('echo hello world');

        $commands = DevCommands::commands();

        $this->assertSame('echo', $commands[0]['name']);
    }

    public function testArtisanPrefixesCommand(): void
    {
        DevCommands::artisan('serve --host=localhost', 'server');

        $commands = DevCommands::commands();

        $this->assertSame('php artisan serve --host=localhost', $commands[0]['command']);
        $this->assertSame('server', $commands[0]['name']);
    }

    public function testArtisanDerivesNameFromCommand(): void
    {
        DevCommands::artisan('queue:listen --tries=1');

        $commands = DevCommands::commands();

        $this->assertSame('queue:listen', $commands[0]['name']);
    }

    public function testNodePrefixesCommandWithDetectedPackageManager(): void
    {
        DevCommands::node('dev', 'vite');

        $this->assertSame('pnpm run dev', DevCommands::commands()[0]['command']);
    }

    public function testNodeExecPrefixesCommandWithDetectedPackageManager(): void
    {
        DevCommands::nodeExec('concurrently', 'processes');

        $this->assertSame('pnpm exec concurrently', DevCommands::commands()[0]['command']);
    }

    public function testExceptExcludesCommands(): void
    {
        DevCommands::register('echo one', 'one');
        DevCommands::register('echo two', 'two');
        DevCommands::register('echo three', 'three');

        DevCommands::except('two');

        $commands = DevCommands::commands();

        $this->assertCount(2, $commands);
        $this->assertSame('one', $commands[0]['name']);
        $this->assertSame('three', $commands[1]['name']);
    }

    public function testOnlyIncludesOnlySpecifiedCommands(): void
    {
        DevCommands::register('echo one', 'one');
        DevCommands::register('echo two', 'two');
        DevCommands::register('echo three', 'three');

        DevCommands::only('one', 'three');

        $commands = DevCommands::commands();

        $this->assertCount(2, $commands);
        $this->assertSame('one', $commands[0]['name']);
        $this->assertSame('three', $commands[1]['name']);
    }

    public function testOnlyTakesPrecedenceOverExcept(): void
    {
        DevCommands::register('echo one', 'one');
        DevCommands::register('echo two', 'two');
        DevCommands::register('echo three', 'three');

        DevCommands::only('one', 'two');
        DevCommands::except('two');

        $commands = DevCommands::commands();

        $this->assertCount(1, $commands);
        $this->assertSame('one', $commands[0]['name']);
    }

    public function testCommandsGetAutoAssignedColors(): void
    {
        DevCommands::register('echo one', 'one');
        DevCommands::register('echo two', 'two');

        $commands = DevCommands::commands();

        $this->assertNotNull($commands[0]['color']);
        $this->assertNotNull($commands[1]['color']);
        $this->assertNotSame($commands[0]['color'], $commands[1]['color']);
    }

    public function testExplicitColorIsPreserved(): void
    {
        DevCommands::register('echo one', 'one')->pink();
        DevCommands::register('echo two', 'two');

        $commands = DevCommands::commands();

        $this->assertSame(DevCommandColor::Pink->value, $commands[0]['color']);
        $this->assertNotSame(DevCommandColor::Pink->value, $commands[1]['color']);
    }

    public function testAutoColorSkipsExplicitlyUsedColors(): void
    {
        DevCommands::register('echo one', 'one')->blue();
        DevCommands::register('echo two', 'two');

        $commands = DevCommands::commands();

        $this->assertSame(DevCommandColor::Blue->value, $commands[0]['color']);
        $this->assertNotSame(DevCommandColor::Blue->value, $commands[1]['color']);
    }

    public function testColorsRecycleWhenAllUsed(): void
    {
        DevCommands::register('cmd1', 'c1');
        DevCommands::register('cmd2', 'c2');
        DevCommands::register('cmd3', 'c3');
        DevCommands::register('cmd4', 'c4');
        DevCommands::register('cmd5', 'c5');
        DevCommands::register('cmd6', 'c6');
        DevCommands::register('cmd7', 'c7');

        $commands = DevCommands::commands();

        $this->assertCount(7, $commands);

        foreach ($commands as $command) {
            $this->assertNotNull($command['color']);
        }
    }

    public function testRegisteringCommandWithSameNameAndSamePriorityOverwritesPrevious(): void
    {
        DevCommands::register('echo old', 'myname');
        DevCommands::register('echo new', 'myname');

        $commands = DevCommands::commands();

        $this->assertCount(1, $commands);
        $this->assertSame('echo new', $commands[0]['command']);
    }

    public function testUserlandOverwritesVendorPriority(): void
    {
        $ref = new ReflectionClass(DevCommands::class);
        $ref->getProperty('commands')->setValue(null, [
            'myname' => new DevCommand('echo vendor', [], 'myname', DevCommand::PRIORITY_VENDOR),
        ]);

        // register() resolves as userland from test code — should overwrite vendor
        DevCommands::register('echo userland', 'myname');

        $result = DevCommands::commands();
        $this->assertSame('echo userland', collect($result)->firstWhere('name', 'myname')['command']);
    }

    public function testUserlandOverwritesDefaultPriority(): void
    {
        // registerDefaults() gets DEFAULT priority, then register() gets USERLAND
        DevCommands::registerDefaults();
        DevCommands::register('custom-server', 'server');

        $result = DevCommands::commands();
        $server = collect($result)->firstWhere('name', 'server');
        $this->assertSame('custom-server', $server['command']);
        $this->assertSame(DevCommand::PRIORITY_USERLAND, $server['priority']);
    }

    public function testDefaultDoesNotOverwriteUserlandPriority(): void
    {
        $ref = new ReflectionClass(DevCommands::class);
        $ref->getProperty('commands')->setValue(null, [
            'server' => new DevCommand('userland-server', [], 'server', DevCommand::PRIORITY_USERLAND),
        ]);

        // registerDefaults() gets DEFAULT priority — should NOT overwrite userland
        DevCommands::registerDefaults();

        $result = DevCommands::commands();
        $this->assertSame('userland-server', collect($result)->firstWhere('name', 'server')['command']);
    }

    public function testDefaultDoesNotOverwriteVendorPriority(): void
    {
        $ref = new ReflectionClass(DevCommands::class);
        $ref->getProperty('commands')->setValue(null, [
            'server' => new DevCommand('vendor-server', [], 'server', DevCommand::PRIORITY_VENDOR),
        ]);

        // registerDefaults() gets DEFAULT priority — should NOT overwrite vendor
        DevCommands::registerDefaults();

        $result = DevCommands::commands();
        $this->assertSame('vendor-server', collect($result)->firstWhere('name', 'server')['command']);
    }

    public function testDefaultPriorityIsLowest(): void
    {
        DevCommands::registerDefaults();

        $commands = DevCommands::commands();
        $serverCommand = collect($commands)->firstWhere('name', 'server');

        $this->assertSame(DevCommand::PRIORITY_DEFAULT, $serverCommand['priority']);
    }

    public function testUserlandRegistrationGetsUserlandPriority(): void
    {
        DevCommands::register('echo hello', 'greeter');

        $commands = DevCommands::commands();

        $this->assertSame(DevCommand::PRIORITY_USERLAND, $commands[0]['priority']);
    }

    public function testResolvePriorityDetectsUserlandThroughDevCommandsFrame(): void
    {
        $ref = new ReflectionClass(DevCommands::class);
        $method = $ref->getMethod('resolvePriority');

        $trace = [
            ['file' => $ref->getFileName(), 'line' => 99, 'function' => 'register', 'class' => DevCommands::class],
            ['file' => base_path('app/Providers/AppServiceProvider.php'), 'line' => 19, 'function' => 'artisan', 'class' => DevCommands::class],
            ['file' => base_path('vendor/hypervel/framework/src/Hypervel/Foundation/Application.php'), 'line' => 896, 'function' => 'register', 'class' => 'App\Providers\AppServiceProvider'],
        ];

        $this->assertSame(DevCommand::PRIORITY_USERLAND, $method->invoke(null, $trace));
    }

    public function testResolvePriorityDetectsVendor(): void
    {
        $ref = new ReflectionClass(DevCommands::class);
        $method = $ref->getMethod('resolvePriority');

        $trace = [
            ['file' => $ref->getFileName(), 'line' => 99, 'function' => 'register', 'class' => DevCommands::class],
            ['file' => base_path('vendor/some-package/src/ServiceProvider.php'), 'line' => 10, 'function' => 'register', 'class' => DevCommands::class],
            ['file' => base_path('vendor/hypervel/framework/src/Hypervel/Foundation/Application.php'), 'line' => 896, 'function' => 'register', 'class' => 'Some\Package\ServiceProvider'],
        ];

        $this->assertSame(DevCommand::PRIORITY_VENDOR, $method->invoke(null, $trace));
    }

    public function testResolvePriorityDoesNotTreatVendorPrefixedSiblingAsVendor(): void
    {
        $method = (new ReflectionClass(DevCommands::class))->getMethod('resolvePriority');

        $this->assertSame(DevCommand::PRIORITY_USERLAND, $method->invoke(null, [[
            'file' => base_path('vendor-tools/ServiceProvider.php'),
            'line' => 10,
        ]]));
    }

    public function testResolvePriorityDetectsUserlandCallingVendorHelper(): void
    {
        $ref = new ReflectionClass(DevCommands::class);
        $method = $ref->getMethod('resolvePriority');

        $trace = [
            ['file' => base_path('vendor/some-package/src/Helper.php'), 'line' => 10, 'function' => 'register', 'class' => DevCommands::class],
            ['file' => base_path('app/Providers/AppServiceProvider.php'), 'line' => 25, 'function' => 'setupDev', 'class' => 'Some\Package\Helper'],
            ['file' => base_path('vendor/hypervel/framework/src/Hypervel/Foundation/Application.php'), 'line' => 896, 'function' => 'register', 'class' => 'App\Providers\AppServiceProvider'],
        ];

        $this->assertSame(DevCommand::PRIORITY_USERLAND, $method->invoke(null, $trace));
    }

    public function testResolvePriorityTreatsComposerPathPackageAsVendor(): void
    {
        $installed = require dirname(__DIR__, 2) . '/vendor/composer/installed.php';
        $pathPackageData = $installed;
        $pathPackageData['versions']['example/path-package'] = [
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'type' => 'library',
            'install_path' => dirname(__DIR__, 2) . '/src/watcher',
            'dev_requirement' => true,
        ];

        InstalledVersions::reload($pathPackageData);

        try {
            $method = (new ReflectionClass(DevCommands::class))->getMethod('resolvePriority');

            $this->assertSame(DevCommand::PRIORITY_VENDOR, $method->invoke(null, [[
                'file' => dirname(__DIR__, 2) . '/src/watcher/src/WatcherServiceProvider.php',
                'line' => 1,
            ]]));
            $this->assertSame(DevCommand::PRIORITY_USERLAND, $method->invoke(null, [[
                'file' => dirname(__DIR__, 2) . '/src/watcher-adjacent/ServiceProvider.php',
                'line' => 1,
            ]]));
        } finally {
            InstalledVersions::reload($installed);
        }
    }

    public function testRootPackageFilesRemainUserland(): void
    {
        $method = (new ReflectionClass(DevCommands::class))->getMethod('resolvePriority');

        $this->assertSame(DevCommand::PRIORITY_USERLAND, $method->invoke(null, [[
            'file' => __FILE__,
            'line' => __LINE__,
        ]]));
    }

    public function testRegisterDefaultsRegistersExpectedCommands(): void
    {
        DevCommands::registerDefaults();

        $commands = DevCommands::commands();

        $this->assertCount(3, $commands);

        $names = array_column($commands, 'name');
        $this->assertContains('server', $names);
        $this->assertContains('queue', $names);
        // REMOVED: Hypervel has no Pail-equivalent command for the default logs process.
        $this->assertContains('vite', $names);

        $this->assertSame('php artisan watch', collect($commands)->firstWhere('name', 'server')['command']);
        $this->assertSame('php artisan queue:listen --tries=1 --timeout=0', collect($commands)->firstWhere('name', 'queue')['command']);
        $this->assertSame('pnpm run dev', collect($commands)->firstWhere('name', 'vite')['command']);
    }

    public function testRegisteredCommandIncludesSource(): void
    {
        DevCommands::register('echo hello', 'greeter');

        $commands = DevCommands::commands();

        $this->assertArrayHasKey('source', $commands[0]);
        $this->assertIsArray($commands[0]['source']);
        $this->assertSame(__CLASS__, $commands[0]['source']['class']);
    }

    public function testFlushStateResetsAllRegistryState(): void
    {
        for ($index = 1; $index <= 7; ++$index) {
            DevCommands::register("command-{$index}", "command-{$index}");
        }

        DevCommands::nodeExec('concurrently', 'processes');
        DevCommands::commands();
        DevCommands::only('processes');
        DevCommands::except('server');

        DevCommands::flushState();

        $reflection = new ReflectionClass(DevCommands::class);

        $this->assertNull($reflection->getProperty('packageManager')->getValue());
        $this->assertSame(0, $reflection->getProperty('colorCount')->getValue());
        $this->assertSame([], $reflection->getProperty('commands')->getValue());
        $this->assertSame([], $reflection->getProperty('only')->getValue());
        $this->assertSame([], $reflection->getProperty('except')->getValue());
    }
}
