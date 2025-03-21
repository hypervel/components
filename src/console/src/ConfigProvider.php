<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Console\Commands\ScheduleClearCacheCommand;
use Hypervel\Console\Commands\ScheduleListCommand;
use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Commands\ScheduleStopCommand;
use Hypervel\Console\Commands\ScheduleTestCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'commands' => [
                ScheduleListCommand::class,
                ScheduleRunCommand::class,
                ScheduleStopCommand::class,
                ScheduleClearCacheCommand::class,
                ScheduleTestCommand::class,
            ],
        ];
    }
}
