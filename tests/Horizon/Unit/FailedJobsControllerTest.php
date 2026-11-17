<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Http\Controllers\FailedJobsController;
use Hypervel\Http\Request;
use Hypervel\Tests\Horizon\UnitTestCase;
use JMac\Testing\Double;
use PHPUnit\Framework\Attributes\TestWith;

class FailedJobsControllerTest extends UnitTestCase
{
    public function testLiteralZeroTagFiltersFailedJobs(): void
    {
        $jobs = Double::for(JobRepository::class)->strict();
        $jobs->expects('getJobs')->with(['job-id'], 0)->returns(collect());
        $jobs->expects('countFailed')->never();

        $tags = Double::for(TagRepository::class)->strict();
        $tags->expects('paginate')->with('failed:0', 0, 50)->returns(['job-id']);
        $tags->expects('count')->with('failed:0')->returns(1);

        $result = (new FailedJobsController($jobs, $tags))->index(
            Request::create('/?tag=0'),
        );

        $this->assertSame(1, $result['total']);
    }

    public function testEmptyTagUsesTheUnfilteredFailedJobList(): void
    {
        $jobs = Double::for(JobRepository::class)->strict();
        $jobs->expects('getFailed')->with(-1)->returns(collect());
        $jobs->expects('countFailed')->returns(2);

        $tags = Double::for(TagRepository::class)->strict();
        $tags->expects('paginate')->never();
        $tags->expects('count')->never();

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
        $jobs = Double::for(JobRepository::class)->strict();
        $jobs->expects('getJobs')->with(['job-id'], $startingAt)->returns(collect());

        $tags = Double::for(TagRepository::class)->strict();
        $tags->expects('paginate')->with('failed:example', $startingAt, 50)->returns(['job-id']);
        $tags->expects('count')->with('failed:example')->returns(1);

        (new FailedJobsController($jobs, $tags))->index(Request::create('/', 'GET', array_filter([
            'tag' => 'example',
            'starting_at' => $cursor,
        ], static fn (?string $value): bool => $value !== null)));
    }
}
