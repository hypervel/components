<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ActiveDrivers;

class QueueConnectionIsAsynchronous extends Diagnostic
{
    public string $name = 'Queue runs asynchronously';

    public string $group = 'queue';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default queue connection configured.',
            'sync-production' => Message::make(
                summary: 'Queued jobs run synchronously in production.',
                remediation: 'Set QUEUE_CONNECTION to a background queue driver such as database, redis, sqs, or beanstalkd.',
            )->link(Link::docs('queues', 'connections-vs-queues')),
            'sync-local' => 'Queued jobs run synchronously.',
            'invalid' => 'The configured queue has no usable concrete connection.',
            'disabled' => 'The null queue driver intentionally discards jobs.',
            'in-process' => 'Queued jobs run asynchronously in application coroutines; no queue worker is required.',
            'async' => 'Queued jobs are processed asynchronously.',
            'async-local' => Message::make(
                summary: 'Queued jobs are processed asynchronously.',
                remediation: 'If jobs are not being processed, make sure a queue worker is running via `php artisan queue:work`.',
            )->link(Link::docs('queues', 'running-the-queue-worker')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $connection = config('queue.default');

        if (! is_string($connection) || $connection === '') {
            return $this->skip('not-configured');
        }

        $connections = ActiveDrivers::queueConnections($connection);

        if ($connections === []) {
            return $this->fail('invalid');
        }

        $driver = $connections[0] === 'null' ? 'null' : config()->string("queue.connections.{$connections[0]}.driver");

        if ($driver === 'null') {
            return $this->skip('disabled');
        }

        if ($driver === 'sync' && EnvironmentMode::current()->isProduction()) {
            return $this->warn('sync-production');
        }

        if ($driver === 'sync') {
            return $this->pass('sync-local');
        }

        if (in_array($driver, ['background', 'deferred'], true)) {
            return $this->pass('in-process');
        }

        if (EnvironmentMode::current()->isProduction()) {
            return $this->pass('async');
        }

        return $this->notice('async-local');
    }
}
