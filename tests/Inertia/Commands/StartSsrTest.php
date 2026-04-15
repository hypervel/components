<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Commands;

use Hypervel\Tests\Inertia\TestCase;
use Symfony\Component\Process\Process;

class StartSsrTest extends TestCase
{
    /** @var null|list<string> */
    protected ?array $processCommand = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.ssr.enabled', true);
        config()->set('inertia.ssr.bundle', __FILE__);
    }

    protected function fakeProcess(): void
    {
        $this->app->bind(Process::class, function ($app, $params) {
            $this->processCommand = $params['command'];

            return new Process(['true']);
        });
    }

    public function testErrorWhenSsrIsDisabled(): void
    {
        config()->set('inertia.ssr.enabled', false);

        $this->artisan('inertia:start-ssr')
            ->expectsOutput('Inertia SSR is not enabled. Enable it via the `inertia.ssr.enabled` config option.')
            ->assertExitCode(1);
    }

    public function testErrorWhenConfiguredBundleNotFound(): void
    {
        config()->set('inertia.ssr.bundle', '/nonexistent/path/ssr.mjs');

        $this->artisan('inertia:start-ssr')
            ->expectsOutput('Inertia SSR bundle not found at the configured path: "/nonexistent/path/ssr.mjs"')
            ->assertExitCode(1);
    }

    public function testErrorWhenNoBundleConfiguredAndDetectionFails(): void
    {
        config()->set('inertia.ssr.bundle', null);

        $this->artisan('inertia:start-ssr')
            ->expectsOutput('Inertia SSR bundle not found. Set the correct Inertia SSR bundle path in your `inertia.ssr.bundle` config.')
            ->assertExitCode(1);
    }

    public function testBundleIsAutoDetectedWhenNotConfigured(): void
    {
        $this->fakeProcess();
        config()->set('inertia.ssr.bundle', null);

        $bundlePath = base_path('bootstrap/ssr/ssr.mjs');
        @mkdir(dirname($bundlePath), recursive: true);
        file_put_contents($bundlePath, '');

        try {
            $this->artisan('inertia:start-ssr')->assertExitCode(0);

            $this->assertSame($bundlePath, $this->processCommand[1]);
        } finally {
            @unlink($bundlePath);
            @rmdir(base_path('bootstrap/ssr'));
        }
    }

    public function testRuntimeDefaultsToNode(): void
    {
        $this->fakeProcess();

        $this->artisan('inertia:start-ssr')->assertExitCode(0);

        $this->assertSame('node', $this->processCommand[0]);
    }

    public function testRuntimeCanBeConfigured(): void
    {
        $this->fakeProcess();
        config()->set('inertia.ssr.runtime', 'bun');

        $this->artisan('inertia:start-ssr')->assertExitCode(0);

        $this->assertSame('bun', $this->processCommand[0]);
    }

    public function testRuntimeCanBeAnAbsolutePath(): void
    {
        $this->fakeProcess();
        config()->set('inertia.ssr.runtime', '/usr/local/bin/node');

        $this->artisan('inertia:start-ssr')->assertExitCode(0);

        $this->assertSame('/usr/local/bin/node', $this->processCommand[0]);
    }

    public function testRuntimeOptionOverridesConfig(): void
    {
        $this->fakeProcess();
        config()->set('inertia.ssr.runtime', 'bun');

        $this->artisan('inertia:start-ssr', ['--runtime' => '/custom/path/node'])->assertExitCode(0);

        $this->assertSame('/custom/path/node', $this->processCommand[0]);
    }

    public function testEnsureRuntimeExistsFailsWhenRuntimeNotFound(): void
    {
        config()->set('inertia.ssr.ensure_runtime_exists', true);
        config()->set('inertia.ssr.runtime', 'nonexistent-runtime-binary');

        $this->artisan('inertia:start-ssr')
            ->expectsOutput('SSR runtime "nonexistent-runtime-binary" could not be found.')
            ->assertExitCode(1);
    }

    public function testEnsureRuntimeExistsPassesWhenRuntimeFound(): void
    {
        $this->fakeProcess();
        config()->set('inertia.ssr.ensure_runtime_exists', true);
        config()->set('inertia.ssr.runtime', 'php');

        $this->artisan('inertia:start-ssr')->assertExitCode(0);
    }

    public function testRuntimeIsNotCheckedByDefault(): void
    {
        $this->fakeProcess();
        config()->set('inertia.ssr.runtime', 'nonexistent-runtime-binary');

        $this->artisan('inertia:start-ssr')->assertExitCode(0);

        $this->assertSame('nonexistent-runtime-binary', $this->processCommand[0]);
    }
}
