<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Http\Controllers\FailedJobsController;
use Hypervel\Http\Request;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

class FailedJobsControllerTest extends UnitTestCase
{
    public function testLiteralZeroTagFiltersFailedJobs(): void
    {
        $jobs = m::mock(JobRepository::class);
        $jobs->shouldReceive('getJobs')->once()->with(['job-id'], 0)->andReturn(collect());
        $jobs->shouldReceive('countFailed')->never();

        $tags = m::mock(TagRepository::class);
        $tags->shouldReceive('paginate')->once()->with('failed:0', 0, 50)->andReturn(['job-id']);
        $tags->shouldReceive('count')->once()->with('failed:0')->andReturn(1);

        $result = (new FailedJobsController($jobs, $tags))->index(
            Request::create('/?tag=0'),
        );

        $this->assertSame(1, $result['total']);
    }

    public function testEmptyTagUsesTheUnfilteredFailedJobList(): void
    {
        $jobs = m::mock(JobRepository::class);
        $jobs->shouldReceive('getFailed')->once()->with(-1)->andReturn(collect());
        $jobs->shouldReceive('countFailed')->once()->andReturn(2);

        $tags = m::mock(TagRepository::class);
        $tags->shouldReceive('paginate')->never();
        $tags->shouldReceive('count')->never();

        $result = (new FailedJobsController($jobs, $tags))->index(
            Request::create('/?tag='),
        );

        $this->assertSame(2, $result['total']);
    }

    #[TestWith([null, 0])]
    #[TestWith(['0', 0])]
    #[TestWith(['-1', 0])]
    #[TestWith(['49', 50])]
    public function testTaggedPaginationUsesTheSameStartForSelectionAndRowIndices(?string $cursor, int $startingAt): void
    {
        $jobs = m::mock(JobRepository::class);
        $jobs->shouldReceive('getJobs')->once()->with(['job-id'], $startingAt)->andReturn(collect());

        $tags = m::mock(TagRepository::class);
        $tags->shouldReceive('paginate')->once()->with('failed:example', $startingAt, 50)->andReturn(['job-id']);
        $tags->shouldReceive('count')->once()->with('failed:example')->andReturn(1);

        (new FailedJobsController($jobs, $tags))->index(Request::create('/', 'GET', array_filter([
            'tag' => 'example',
            'starting_at' => $cursor,
        ], static fn (?string $value): bool => $value !== null)));
    }
}
