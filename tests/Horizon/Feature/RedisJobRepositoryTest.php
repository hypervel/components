<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Exception;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\JobPayload;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class RedisJobRepositoryTest extends IntegrationTestCase
{
    public function testItCanFindAFailedJobByItsId()
    {
        $repository = $this->app->get(JobRepository::class);
        $payload = new JobPayload(json_encode(['id' => '1', 'displayName' => 'foo']));

        $repository->failed(new Exception('Failed Job'), 'redis', 'default', $payload);

        $this->assertSame('1', $repository->findFailed('1')->id);
    }

    public function testItWillNotFindAFailedJobIfTheJobHasNotFailed()
    {
        $repository = $this->app->get(JobRepository::class);
        $payload = new JobPayload(json_encode(['id' => '1', 'displayName' => 'foo']));

        $repository->pushed('redis', 'default', $payload);

        $this->assertNull($repository->findFailed('1'));
    }

    public function testItSavesMicrosecondsAsAFloatAndDisregardsTheLocale()
    {
        $originalLocale = setlocale(LC_NUMERIC, 0);

        setlocale(LC_NUMERIC, 'fr_FR');

        try {
            $repository = $this->app->get(JobRepository::class);
            $payload = new JobPayload(json_encode(['id' => '1', 'displayName' => 'foo']));

            $repository->pushed('redis', 'default', $payload);
            $repository->reserved('redis', 'default', $payload);

            $result = $repository->getRecent()[0];

            $this->assertEquals('1', $result->id);
            $this->assertStringNotContainsString(',', $result->reserved_at);
        } catch (Throwable $e) {
            setlocale(LC_NUMERIC, $originalLocale);

            throw $e;
        }
    }

    public function testItRemovesRecentJobsWhenQueueIsPurged()
    {
        $repository = $this->app->get(JobRepository::class);

        $repository->pushed('horizon', 'email-processing', new JobPayload(json_encode(['id' => '1', 'displayName' => 'first'])));
        $repository->pushed('horizon', 'email-processing', new JobPayload(json_encode(['id' => '2', 'displayName' => 'second'])));
        $repository->pushed('horizon', 'email-processing', new JobPayload(json_encode(['id' => '3', 'displayName' => 'third'])));
        $repository->pushed('horizon', 'email-processing', new JobPayload(json_encode(['id' => '4', 'displayName' => 'fourth'])));
        $repository->pushed('horizon', 'email-processing', new JobPayload(json_encode(['id' => '5', 'displayName' => 'fifth'])));

        $repository->completed(new JobPayload(json_encode(['id' => '1', 'displayName' => 'first'])));
        $repository->completed(new JobPayload(json_encode(['id' => '2', 'displayName' => 'second'])));

        $this->assertEquals(3, $repository->purge('email-processing'));
        $this->assertEquals(2, $repository->countRecent());
        $this->assertEquals(0, $repository->countPending());
        $this->assertEquals(2, $repository->countCompleted());

        $recent = collect($repository->getRecent());
        $this->assertNotNull($recent->firstWhere('id', 1));
        $this->assertNotNull($recent->firstWhere('id', 2));
        $this->assertCount(2, $repository->getJobs(['1', '2', '3', '4', '5']));
    }

    public function testItWillDeleteAFailedJob()
    {
        $repository = $this->app->get(JobRepository::class);
        $payload = new JobPayload(json_encode(['id' => '1', 'displayName' => 'foo']));

        $repository->failed(new Exception('Failed Job'), 'redis', 'default', $payload);

        $this->assertEquals('foo', $repository->findFailed('1')->name);

        $result = $repository->deleteFailed('1');

        $this->assertSame(1, $result);
        $this->assertNull($repository->findFailed('1'));
    }

    public function testItWillNotDeleteAJobIfTheJobHasNotFailed()
    {
        $repository = $this->app->get(JobRepository::class);
        $payload = new JobPayload(json_encode(['id' => '1', 'displayName' => 'foo']));

        $repository->pushed('redis', 'default', $payload);

        $result = $repository->deleteFailed('1');

        $this->assertSame(0, $result);
        $this->assertSame('1', $repository->getRecent()[0]->id);
    }
}
