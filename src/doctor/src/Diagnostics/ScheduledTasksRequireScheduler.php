<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;

class ScheduledTasksRequireScheduler extends Diagnostic
{
    public string $name = 'Scheduled tasks registered';

    public string $group = 'scheduler';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'no-tasks' => 'The application does not have scheduled tasks.',
            'tasks-registered' => Message::make(
                summary: 'The application has scheduled tasks.',
                remediation: 'Keep `php artisan schedule:run` running; supervise this long-running process in production.',
            )->link(Link::docs('scheduling', 'running-the-scheduler')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (app(Schedule::class)->events() === []) {
            return $this->pass('no-tasks');
        }

        return $this->notice('tasks-registered');
    }
}
