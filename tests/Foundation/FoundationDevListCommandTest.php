<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Foundation\Console\DevListCommand;
use Hypervel\Foundation\DevCommand;
use Hypervel\Foundation\DevCommands;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use ReflectionClass;

class FoundationDevListCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DevCommands::flushState();
        $this->app->make(Kernel::class)->getArtisan()->resolve(DevListCommand::class);
    }

    public function testListsRegisteredCommands(): void
    {
        DevCommands::register('echo hello', 'greeter');
        DevCommands::register('php artisan serve', 'server');

        $this->artisan('dev:list')
            ->expectsOutputToContain('greeter')
            ->expectsOutputToContain('server')
            ->expectsOutputToContain('Showing [2] dev commands')
            ->assertSuccessful();
    }

    public function testShowsSingularCommandLabel(): void
    {
        DevCommands::register('echo hello', 'greeter');

        $this->artisan('dev:list')
            ->expectsOutputToContain('Showing [1] dev command ')
            ->assertSuccessful();
    }

    public function testOmitsSourceWhenTerminalHasNoRoomWithoutTruncatingCommand(): void
    {
        $columns = getenv('COLUMNS');
        putenv('COLUMNS=1');

        try {
            DevCommands::register('php artisan queue:listen --tries=1', 'queue');

            $this->artisan('dev:list')
                ->expectsOutputToContain('php artisan queue:listen --tries=1')
                ->doesntExpectOutputToContain(__CLASS__)
                ->assertSuccessful();
        } finally {
            $columns === false ? putenv('COLUMNS') : putenv("COLUMNS={$columns}");
        }
    }

    public function testJsonOutputContainsAllFields(): void
    {
        DevCommands::register('echo hello', 'greeter');

        $this->artisan('dev:list', ['--json' => true])
            ->assertSuccessful();

        $this->artisan('dev:list', ['--json' => true])
            ->expectsOutput(json_encode(array_map(fn (array $command): array => array_merge($command, [
                'source' => $this->formatSource($command['source']),
            ]), DevCommands::commands()), JSON_THROW_ON_ERROR))
            ->assertSuccessful();
    }

    public function testFilterByName(): void
    {
        DevCommands::register('echo hello', 'greeter');
        DevCommands::register('php artisan serve', 'server');

        $this->artisan('dev:list', ['--filter' => 'server', '--json' => true])
            ->assertSuccessful();
    }

    public function testFilterByCommand(): void
    {
        DevCommands::register('echo hello', 'greeter');
        DevCommands::register('php artisan serve', 'server');

        $this->artisan('dev:list', ['--filter' => 'artisan', '--json' => true])
            ->assertSuccessful();
    }

    public function testFilterReturnsFailureWhenNoMatch(): void
    {
        DevCommands::register('echo hello', 'greeter');

        $this->artisan('dev:list', ['--filter' => 'nonexistent', '--json' => true])
            ->assertFailed();
    }

    public function testEmptyStateWithNoCommands(): void
    {
        $this->artisan('dev:list')
            ->expectsOutputToContain("doesn't have any dev processes")
            ->assertSuccessful();
    }

    public function testEmptyStateWithFilterReturnsFailure(): void
    {
        DevCommands::register('echo hello', 'greeter');

        $this->artisan('dev:list', ['--filter' => 'nonexistent'])
            ->expectsOutputToContain("doesn't have any dev processes matching the given criteria")
            ->assertFailed();
    }

    public function testExceptVendorExcludesVendorCommands(): void
    {
        DevCommands::register('echo hello', 'app-cmd');
        $this->registerVendorCommand('echo vendor', 'vendor-cmd');

        $output = $this->getJsonOutput(['--except-vendor' => true]);

        $this->assertCount(1, $output);
        $this->assertSame('app-cmd', $output[0]['name']);
    }

    public function testOnlyVendorWithNoVendorCommandsReturnsEmpty(): void
    {
        DevCommands::register('echo hello', 'app-cmd');

        $this->artisan('dev:list', ['--only-vendor' => true, '--json' => true])
            ->assertFailed();
    }

    public function testOnlyVendorIncludesVendorCommands(): void
    {
        DevCommands::register('echo hello', 'app-cmd');
        $this->registerVendorCommand('echo vendor', 'vendor-cmd');

        $output = $this->getJsonOutput(['--only-vendor' => true]);

        $this->assertCount(1, $output);
        $this->assertSame('vendor-cmd', $output[0]['name']);
    }

    public function testJsonOutputWithFilterContainsOnlyMatchingCommands(): void
    {
        DevCommands::register('echo hello', 'greeter');
        DevCommands::register('php artisan serve', 'server');
        DevCommands::register('php artisan queue:listen', 'queue');

        $output = $this->getJsonOutput(['--filter' => 'artisan']);

        $this->assertCount(2, $output);
        $this->assertSame('server', $output[0]['name']);
        $this->assertSame('queue', $output[1]['name']);
    }

    public function testJsonOutputIncludesSource(): void
    {
        DevCommands::register('echo hello', 'greeter');

        $output = $this->getJsonOutput();

        $this->assertArrayHasKey('source', $output[0]);
        $this->assertStringContainsString(__CLASS__, $output[0]['source']);
    }

    public function testFormatSourceWithClassAndFunction(): void
    {
        $command = new DevListCommand;

        $ref = new ReflectionClass($command);
        $method = $ref->getMethod('formatSource');

        $result = $method->invoke($command, [
            'file' => '/some/path.php',
            'line' => 42,
            'class' => 'App\Providers\AppServiceProvider',
            'function' => 'boot',
        ]);

        $this->assertSame('App\Providers\AppServiceProvider@boot', $result);
    }

    public function testFormatSourceWithFileAndLine(): void
    {
        $command = new DevListCommand;

        $ref = new ReflectionClass($command);
        $method = $ref->getMethod('formatSource');

        $result = $method->invoke($command, [
            'file' => '/app/routes/console.php',
            'line' => 15,
        ]);

        $this->assertSame('/app/routes/console.php:15', $result);
    }

    public function testFormatSourceWithEmptyArray(): void
    {
        $command = new DevListCommand;

        $ref = new ReflectionClass($command);
        $method = $ref->getMethod('formatSource');

        $result = $method->invoke($command, []);

        $this->assertSame('', $result);
    }

    public function testCombinedFilterAndVendorOptions(): void
    {
        DevCommands::register('echo hello', 'greeter');
        DevCommands::register('php artisan serve', 'server');

        $output = $this->getJsonOutput(['--filter' => 'server', '--except-vendor' => true]);

        $this->assertCount(1, $output);
        $this->assertSame('server', $output[0]['name']);
    }

    protected function getJsonOutput(array $options = []): array
    {
        $options['--json'] = true;

        Artisan::call('dev:list', $options);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function formatSource(array $source): string
    {
        $class = $source['class'] ?? null;
        $function = $source['function'] ?? null;

        if ($class) {
            return "{$class}@{$function}";
        }

        return implode(':', array_filter([$source['file'] ?? null, $source['line'] ?? null]));
    }

    /**
     * Register a command with vendor priority.
     */
    protected function registerVendorCommand(string $command, string $name): void
    {
        $property = (new ReflectionClass(DevCommands::class))->getProperty('commands');
        $commands = $property->getValue();
        $commands[$name] = new DevCommand($command, [], $name, DevCommand::PRIORITY_VENDOR);
        $property->setValue(null, $commands);
    }
}
