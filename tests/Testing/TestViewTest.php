<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\TestViewTest;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testing\TestView;
use Hypervel\Tests\TestCase;
use Hypervel\View\View;
use Mockery as m;
use PHPUnit\Framework\AssertionFailedError;

class TestViewTest extends TestCase
{
    public function testAssertViewHasAcceptsTheExactKeylessModel(): void
    {
        $model = new TestModel;

        $this->makeTestView(['foo' => $model])->assertViewHas('foo', $model);
    }

    public function testAssertViewHasRejectsADifferentKeylessModel(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foo' => new TestModel])->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasAcceptsAModelWithTheSameStoredIdentity(): void
    {
        $this->makeTestView(['foo' => new TestModel(['id' => 1])])
            ->assertViewHas('foo', new TestModel(['id' => 1]));
    }

    public function testAssertViewHasAcceptsACollectionContainingTheExactKeylessModels(): void
    {
        $collection = new EloquentCollection([new TestModel, new TestModel]);

        $this->makeTestView(['foos' => $collection])->assertViewHas('foos', $collection);
    }

    public function testAssertViewHasRejectsACollectionContainingDifferentKeylessModels(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView([
            'foos' => new EloquentCollection([new TestModel, new TestModel]),
        ])->assertViewHas('foos', new EloquentCollection([new TestModel, new TestModel]));
    }

    public function testAssertViewHasAcceptsACollectionWithTheSameStoredIdentities(): void
    {
        $this->makeTestView([
            'foos' => new EloquentCollection([
                new TestModel(['id' => 1]),
                new TestModel(['id' => 2]),
            ]),
        ])->assertViewHas('foos', new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]));
    }

    public function testAssertViewHasRejectsANonModelAsAnAssertionFailure(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foo' => 'not a model'])->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasReportsAMissingCollectionKeyAsAnAssertionFailure(): void
    {
        $actual = new EloquentCollection([
            0 => new TestModel(['id' => 1]),
            1 => new TestModel(['id' => 2]),
        ]);
        $expected = new EloquentCollection([
            0 => new TestModel(['id' => 1]),
            2 => new TestModel(['id' => 2]),
        ]);

        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foos' => $actual])->assertViewHas('foos', $expected);
    }

    private function makeTestView(array $data): TestView
    {
        return new TestView(m::mock(View::class, [
            'render' => 'hello world',
            'gatherData' => $data,
        ]));
    }
}

class TestModel extends Model
{
    protected array $guarded = [];
}
