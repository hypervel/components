<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Jobs;

use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\MakeSearchableUniquely;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use JsonException;

class MakeSearchableTest extends ScoutTestCase
{
    public function testJobPropertiesAreSetFromConfig(): void
    {
        config()->set('scout.jobs', [
            'tries' => 3,
            'backoff' => [1, 5, 10],
            'max_exceptions' => 2,
        ]);

        $job = new MakeSearchable(Collection::make([$this->model(1)]));

        $this->assertSame(3, $job->tries);
        $this->assertSame([1, 5, 10], $job->backoff);
        $this->assertSame(2, $job->maxExceptions);
    }

    public function testJobPropertiesAreNotSetWithoutConfig(): void
    {
        $job = new MakeSearchable(Collection::make([$this->model(1)]));

        $this->assertNull($job->tries);
        $this->assertNull($job->backoff);
        $this->assertNull($job->maxExceptions);
    }

    public function testSubclassJobPropertiesAreNotOverriddenByConfig(): void
    {
        config()->set('scout.jobs', [
            'tries' => 1,
            'backoff' => [1, 5, 10],
            'max_exceptions' => 1,
        ]);

        $job = new OverriddenMakeSearchable(Collection::make([$this->model(1)]));

        $this->assertSame(5, $job->tries);
        $this->assertSame([2, 4, 8, 16, 32], $job->backoff());
        $this->assertSame(3, $job->maxExceptions);
    }

    public function testJobFailsOnTimeoutByDefault(): void
    {
        $job = new MakeSearchable(Collection::make([$this->model(1)]));

        $this->assertTrue($job->failOnTimeout);
    }

    public function testSubclassCanOptOutOfFailingOnTimeout(): void
    {
        $job = new OverriddenMakeSearchable(Collection::make([$this->model(1)]));

        $this->assertFalse($job->failOnTimeout);
    }

    public function testUniqueIdIsBasedOnTheClassAndScoutKeys(): void
    {
        $models = Collection::make([$this->model(2), $this->model(1)]);

        $expected = hash('sha256', json_encode([
            SearchableModel::class,
            [1, 2],
        ], JSON_THROW_ON_ERROR));

        $job = new MakeSearchableUniquely($models);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(3600, $job->uniqueFor);
        $this->assertSame($expected, $job->uniqueId());
    }

    public function testUniqueIdIsNotAffectedByModelOrder(): void
    {
        $models = Collection::make([$this->model(3), $this->model(1), $this->model(2)]);

        $this->assertSame(
            (new MakeSearchableUniquely($models))->uniqueId(),
            (new MakeSearchableUniquely($models->reverse()->values()))->uniqueId()
        );
    }

    public function testUniqueIdDiffersForDifferentModelClasses(): void
    {
        $first = Collection::make([$this->model(1)]);
        $second = Collection::make([(new OtherUniqueSearchableModel)->setAttribute('id', 1)]);

        $this->assertNotSame(
            (new MakeSearchableUniquely($first))->uniqueId(),
            (new MakeSearchableUniquely($second))->uniqueId()
        );
    }

    public function testInvalidScoutKeyJsonFailsLoudly(): void
    {
        $models = Collection::make([(new InvalidUniqueSearchableModel)->setAttribute('id', 1)]);

        $this->expectException(JsonException::class);

        (new MakeSearchableUniquely($models))->uniqueId();
    }

    private function model(int $id): SearchableModel
    {
        return (new SearchableModel)->setAttribute('id', $id);
    }
}

class OverriddenMakeSearchable extends MakeSearchable
{
    public $tries = 5;

    public $maxExceptions = 3;

    public $failOnTimeout = false;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 4, 8, 16, 32];
    }
}

class OtherUniqueSearchableModel extends SearchableModel
{
}

class InvalidUniqueSearchableModel extends SearchableModel
{
    public function getScoutKey(): mixed
    {
        return "\xB1\x31";
    }
}
