<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Listeners;

use Exception;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Events\JobFailed;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class StoreTagsForFailedTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testTemporaryFailedJobShouldBeDeletedWhenTheMainJobIsDeleted(): void
    {
        config()->set('horizon.trim.failed', 120);

        $tagRepository = m::mock(TagRepository::class);

        $tagRepository->shouldReceive('addTemporary')->once()->with(120, '1', ['failed:foobar'])->andReturn([]);

        $this->instance(TagRepository::class, $tagRepository);

        $event = new JobFailed(
            new Exception('job failed'),
            new FailedJob(),
            '{"id":"1","displayName":"displayName","tags":["foobar"]}'
        );
        $event->connection('redis')->queue('default');

        $this->app->make(Dispatcher::class)->dispatch($event);
    }
}

class FailedJob extends Job
{
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
