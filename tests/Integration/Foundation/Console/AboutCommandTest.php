<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Assert;

use function Hypervel\Testbench\remote;

class AboutCommandTest extends TestCase
{
    public function testItCanDisplayAboutCommandAsJson(): void
    {
        $process = remote('about --json', ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'])->mustRun();

        tap(json_decode($process->getOutput(), true), function (array $output): void {
            Assert::assertArraySubset([
                'php_version' => PHP_VERSION,
                'swoole_version' => swoole_version(),
                'environment' => 'local',
                'debug_mode' => true,
            ], $output['environment']);

            $this->assertTrue($output['cache']['aop_proxies']);
        });
    }

    public function testItDisplaysAnEmptyUrlWhenTheApplicationHasNoCanonicalUrl(): void
    {
        config(['app.url' => null]);
        $this->withoutMockingConsoleOutput();

        $this->artisan(AboutCommand::class, ['--json' => null]);

        $output = json_decode(Artisan::output(), true);

        $this->assertSame('', $output['environment']['url']);
    }

    #[WithEnv('VIEW_COMPILED_PATH', __DIR__ . '/Fixtures/compiled-views')]
    public function testItRespectsCustomPathForCompiledViews(): void
    {
        $process = remote('about --json', ['APP_ENV' => 'local'])->mustRun();

        tap(json_decode($process->getOutput(), true), static function (array $output) {
            Assert::assertArraySubset([
                'views' => true,
            ], $output['cache']);
        });
    }

    public function testItReportsCompiledViewsWhenCached(): void
    {
        remote('view:cache')->mustRun();

        $process = remote('about --json', ['APP_ENV' => 'local'])->mustRun();

        tap(json_decode($process->getOutput(), true), static function (array $output) {
            Assert::assertArraySubset([
                'views' => true,
            ], $output['cache']);
        });
    }
}
