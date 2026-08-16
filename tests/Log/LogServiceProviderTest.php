<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Core\Logger\StdoutLogger;
use Hypervel\Log\LogManager;
use Hypervel\Log\LogServiceProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Output\BufferedOutput;

class LogServiceProviderTest extends TestCase
{
    public function testReloadConfigurationClearsChannelsAndRefreshesTheRetainedStdoutLogger(): void
    {
        $config = new Repository([
            'app' => [
                'stdout_log' => [
                    'level' => [LogLevel::ERROR],
                    'format' => 'line',
                ],
            ],
        ]);
        $output = new BufferedOutput;
        $logger = new StdoutLogger($config, $output);
        $manager = m::mock(LogManager::class);
        $manager->shouldReceive('forgetChannels')->once()->andReturnSelf();
        $application = m::mock(Application::class);
        $application->shouldReceive('resolved')->once()->with('log')->andReturnTrue();
        $application->shouldReceive('make')->once()->with('log')->andReturn($manager);
        $application->shouldReceive('resolved')->once()->with(StdoutLoggerInterface::class)->andReturnTrue();
        $application->shouldReceive('make')->once()->with(StdoutLoggerInterface::class)->andReturn($logger);

        $config->set('app.stdout_log.level', [LogLevel::INFO]);
        $config->set('app.stdout_log.format', 'json');
        (new LogServiceProvider($application))->reloadConfiguration();
        $logger->info('Refreshed.');

        $this->assertSame('Refreshed.', json_decode(
            $output->fetch(),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['message']);
    }

    public function testReloadConfigurationLeavesAnApplicationStdoutLoggerAlone(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldNotReceive('reloadConfiguration');
        $application = m::mock(Application::class);
        $application->shouldReceive('resolved')->once()->with('log')->andReturnFalse();
        $application->shouldReceive('resolved')->once()->with(StdoutLoggerInterface::class)->andReturnTrue();
        $application->shouldReceive('make')->once()->with(StdoutLoggerInterface::class)->andReturn($logger);

        (new LogServiceProvider($application))->reloadConfiguration();
    }
}
