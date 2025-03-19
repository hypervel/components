<?php

declare(strict_types=1);

namespace Hypervel\Scheduling;

use Hypervel\Scheduling\Console\ScheduleClearCacheCommand;
use Hypervel\Scheduling\Console\ScheduleListCommand;
use Hypervel\Scheduling\Console\ScheduleRunCommand;
use Hypervel\Scheduling\Console\ScheduleStopCommand;
use Hypervel\Scheduling\Console\ScheduleTestCommand;

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
