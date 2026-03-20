<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Assert;

use function Hypervel\Testbench\remote;

/**
 * @internal
 * @coversNothing
 */
class AboutCommandTest extends TestCase
{
    public function testItCanDisplayAboutCommandAsJson()
    {
        $process = remote('about --json', ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'])->mustRun();

        tap(json_decode($process->getOutput(), true), function ($output) {
            Assert::assertArraySubset([
                'php_version' => PHP_VERSION,
                'swoole_version' => swoole_version(),
                'environment' => 'local',
                'debug_mode' => true,
            ], $output['environment']);

            $this->assertArrayHasKey('runtime_proxy', $output['cache']);
        });
    }

    public function testItReportsCompiledViewsWhenCached()
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
