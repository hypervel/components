<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Grpc\Console\InstallCommand;
use Hypervel\Grpc\GrpcServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class InstallCommandTest extends TestCase
{
    /** @var array<string, null|string> */
    private array $originalFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $files = new Filesystem;

        foreach ($this->publishedFiles() as $path) {
            $this->originalFiles[$path] = $files->isFile($path) ? $files->get($path) : null;
        }
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $actions = [];

        foreach ($this->originalFiles as $path => $contents) {
            $actions[] = static function () use ($files, $path, $contents): void {
                if ($contents !== null) {
                    $files->replace($path, $contents);

                    return;
                }

                if ($files->isFile($path) && ! $files->delete($path)) {
                    throw new RuntimeException("Unable to delete the gRPC install test file [{$path}].");
                }
            };
        }

        $actions[] = fn () => parent::tearDown();

        CleanupActions::run(...$actions);
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [GrpcServiceProvider::class];
    }

    public function testPublishesCanonicalResourcesWithoutMutatingBootstrapOrEnvironment(): void
    {
        $files = new Filesystem;
        $bootstrapPath = base_path('bootstrap/app.php');
        $environmentPath = $this->app->environmentFilePath();
        $bootstrap = $files->get($bootstrapPath);
        $environment = $files->isFile($environmentPath) ? $files->get($environmentPath) : null;

        $this->artisan('grpc:install')
            ->expectsOutputToContain('gRPC resources installed successfully.')
            ->expectsOutputToContain('Set GRPC_SERVER_ENABLED=true')
            ->assertSuccessful();

        $this->assertSame($files->get($this->sourceConfig()), $files->get(config_path('grpc.php')));
        $this->assertSame($files->get($this->sourceRoutes()), $files->get(base_path('routes/grpc.php')));
        $this->assertStringContainsString("Grpc::unary('Check'", $files->get(base_path('routes/grpc.php')));
        $this->assertStringContainsString("Grpc::unary('List'", $files->get(base_path('routes/grpc.php')));
        $this->assertStringContainsString("Grpc::serverStream('Watch'", $files->get(base_path('routes/grpc.php')));
        $this->assertSame($bootstrap, $files->get($bootstrapPath));
        $this->assertSame(
            $environment,
            $files->isFile($environmentPath) ? $files->get($environmentPath) : null,
        );
    }

    public function testPreservesExistingResourcesWithoutForce(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(config_path());
        $files->ensureDirectoryExists(base_path('routes'));
        $files->put(config_path('grpc.php'), 'custom config');
        $files->put(base_path('routes/grpc.php'), 'custom routes');

        $this->artisan('grpc:install')->assertSuccessful();

        $this->assertSame('custom config', $files->get(config_path('grpc.php')));
        $this->assertSame('custom routes', $files->get(base_path('routes/grpc.php')));
    }

    public function testForceRestoresCurrentPackageResources(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(config_path());
        $files->ensureDirectoryExists(base_path('routes'));
        $files->put(config_path('grpc.php'), 'custom config');
        $files->put(base_path('routes/grpc.php'), 'custom routes');

        $this->artisan('grpc:install', ['--force' => true])->assertSuccessful();

        $this->assertSame($files->get($this->sourceConfig()), $files->get(config_path('grpc.php')));
        $this->assertSame($files->get($this->sourceRoutes()), $files->get(base_path('routes/grpc.php')));
    }

    public function testReportsAResourcePublishingFailure(): void
    {
        $this->app->singleton(InstallCommand::class, FailingGrpcInstallCommand::class);

        $this->artisan('grpc:install')
            ->expectsOutputToContain('Unable to install the gRPC resources.')
            ->assertExitCode(HypervelCommand::FAILURE);
    }

    /**
     * Return files owned by the install command.
     *
     * @return list<string>
     */
    private function publishedFiles(): array
    {
        return [
            config_path('grpc.php'),
            base_path('routes/grpc.php'),
        ];
    }

    /**
     * Return the package configuration source path.
     */
    private function sourceConfig(): string
    {
        return dirname(__DIR__, 2) . '/src/grpc/config/grpc.php';
    }

    /**
     * Return the package route source path.
     */
    private function sourceRoutes(): string
    {
        return dirname(__DIR__, 2) . '/src/grpc/stubs/grpc.php';
    }
}

class FailingGrpcInstallCommand extends InstallCommand
{
    public function callSilent(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::FAILURE;
    }
}
