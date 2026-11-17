<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Listeners;

use Exception;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Events\JobFailed;
use Hypervel\Horizon\Repositories\RedisJobRepository;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class StoreTagsForFailedTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testTemporaryFailedJobShouldBeDeletedWhenTheMainJobIsDeleted(): void
    {
        config()->set('horizon.trim.failed', 120);

        $tagRepository = Double::for(TagRepository::class)->strict();

        $tagRepository->expects('addTemporary')->with(120, '1', ['failed:foobar']);

        $this->instance(TagRepository::class, $tagRepository);

        $event = new JobFailed(
            new Exception('job failed'),
            new FailedJob,
            '{"id":"1","displayName":"displayName","tags":["foobar"]}'
        );
        $event->connection('redis')->queue('default');

        $this->app->make(Dispatcher::class)->dispatch($event);
    }

    public function testFailedJobTrimUsesItsDefaultWhenTrimConfigurationIsReplaced(): void
    {
        config()->set('horizon.trim', [
            'recent' => 60,
            'pending' => 60,
            'completed' => 60,
            'recent_failed' => 10080,
            'monitored' => 10080,
        ]);

        $tagRepository = Double::for(TagRepository::class)->strict();
        $tagRepository->expects('addTemporary')->with(
            RedisJobRepository::DEFAULT_FAILED_JOB_RETENTION,
            '1',
            ['failed:foobar'],
        );
        $this->instance(TagRepository::class, $tagRepository);

        $event = new JobFailed(
            new Exception('job failed'),
            new FailedJob,
            '{"id":"1","displayName":"displayName","tags":["foobar"]}'
        );
        $event->connection('redis')->queue('default');

        $this->app->make(Dispatcher::class)->dispatch($event);
    }
}

class FailedJob extends Job
{
    protected string $connectionName = 'redis';

    protected string $queue = 'default';

    public function getJobId(): int|string|null
    {
        return '1';
    }

    public function getRawBody(): string
    {
        return '';
    }

    public function attempts(): int
    {
        return 1;
    }
}
