<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Controller;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\JobPayload;
use Hypervel\Tests\Horizon\ControllerTestCase;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class MonitoringControllerTest extends ControllerTestCase
{
    public function testMonitoredTagsAndJobCountsAreReturned()
    {
        $tags = Mockery::mock(TagRepository::class);

        $tags->shouldReceive('monitoring')->andReturn(['first', 'second']);
        $tags->shouldReceive('count')->with('first')->andReturn(1);
        $tags->shouldReceive('count')->with('failed:first')->andReturn(1);
        $tags->shouldReceive('count')->with('second')->andReturn(2);
        $tags->shouldReceive('count')->with('failed:second')->andReturn(2);

        $this->app->instance(TagRepository::class, $tags);

        $response = $this->actingAs(new Fakes\User())
            ->get('/horizon/api/monitoring');

        $response->assertJson([
            ['tag' => 'first', 'count' => 2],
            ['tag' => 'second', 'count' => 4],
        ]);
    }

    public function testMonitoredJobsCanBePaginatedByTag()
    {
        $tags = resolve(TagRepository::class);
        $jobs = resolve(JobRepository::class);

        // Add monitored jobs...
        for ($i = 0; $i < 50; ++$i) {
            $tags->add((string) $i, ['tag']);

            $jobs->remember('redis', 'default', new JobPayload(
                json_encode(['id' => (string) $i, 'displayName' => 'foo'])
            ));
        }

        // Paginate first set...
        $response = $this->actingAs(new Fakes\User())
            ->get('/horizon/api/monitoring/tag?tag=tag');

        $results = $response->json('jobs');

        $this->assertCount(25, $results);
        $this->assertSame('49', $results[0]['id']);
        $this->assertSame('25', $results[24]['id']);

        // Paginate second set...
        $response = $this->actingAs(new Fakes\User())
            ->get('/horizon/api/monitoring/tag?starting_at=25&tag=tag');

        $results = $response->json('jobs');

        $this->assertCount(25, $results);
        $this->assertSame('24', $results[0]['id']);
        $this->assertSame('0', $results[24]['id']);
        $this->assertSame(25, $results[0]['index']);
        $this->assertSame(49, $results[24]['index']);
    }

    public function testCanPaginateWhereJobsDontExist()
    {
        $tags = resolve(TagRepository::class);

        for ($i = 0; $i < 50; ++$i) {
            $tags->add((string) $i, ['tag']);
        }

        $response = $this->actingAs(new Fakes\User())
            ->get('/horizon/api/monitoring/tag?tag=tagstarting_at=1000');

        $this->assertCount(0, $response->json('jobs'));
    }

    public function testCanStartMonitoringTags()
    {
        $tags = resolve(TagRepository::class);

        $this->actingAs(new Fakes\User())
            ->post('/horizon/api/monitoring', ['tag' => 'taylor']);

        $this->assertEquals(['taylor'], $tags->monitoring());
    }

    public function testCanStopMonitoringTags()
    {
        $tags = resolve(TagRepository::class);
        $jobs = resolve(JobRepository::class);

        // Add monitored jobs...
        for ($i = 0; $i < 50; ++$i) {
            $tags->add((string) $i, ['tag']);

            $jobs->remember('redis', 'default', new JobPayload(
                json_encode(['id' => (string) $i, 'displayName' => 'foo'])
            ));
        }

        $this->actingAs(new Fakes\User())
            ->delete('/horizon/api/monitoring/tag');

        // Ensure monitored jobs were deleted...
        $response = $this->actingAs(new Fakes\User())
            ->get('/horizon/api/monitoring/tag?tag=tag');

        $results = $response->json('jobs');

        $this->assertCount(0, $results);
    }
}
