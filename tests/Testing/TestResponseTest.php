<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\TestResponseTest;

use Hypervel\Contracts\View\View;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestResponseTest extends TestCase
{
    public function testAssertViewHasModel(): void
    {
        $model = new TestModel(['id' => 1]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => $model],
        ]);

        $response->assertViewHas('foo', $model);
    }

    public function testAssertViewHasEloquentCollection(): void
    {
        $collection = new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
            new TestModel(['id' => 3]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $collection],
        ]);

        $response->assertViewHas('foos', $collection);
    }

    public function testAssertViewHasEloquentCollectionRespectsOrder(): void
    {
        $collection = new EloquentCollection([
            new TestModel(['id' => 3]),
            new TestModel(['id' => 2]),
            new TestModel(['id' => 1]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $collection],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foos', $collection->reverse()->values());
    }

    public function testAssertViewHasEloquentCollectionRespectsType(): void
    {
        $actual = new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $actual],
        ]);

        $expected = new EloquentCollection([
            new AnotherTestModel(['id' => 1]),
            new AnotherTestModel(['id' => 2]),
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foos', $expected);
    }

    public function testAssertViewHasEloquentCollectionRespectsSize(): void
    {
        $actual = new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $actual],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foos', $actual->concat([new TestModel(['id' => 3])]));
    }

    public function testAssertViewHasAcceptsTheExactKeylessModel(): void
    {
        $model = new TestModel;

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => $model],
        ]);

        $response->assertViewHas('foo', $model);
    }

    public function testAssertViewHasRejectsADifferentKeylessModel(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => new TestModel],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasAcceptsAModelWithTheSameStoredIdentity(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => new TestModel(['id' => 1])],
        ]);

        $response->assertViewHas('foo', new TestModel(['id' => 1]));
    }

    public function testAssertViewHasAcceptsACollectionContainingTheExactKeylessModels(): void
    {
        $collection = new EloquentCollection([new TestModel, new TestModel]);

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $collection],
        ]);

        $response->assertViewHas('foos', $collection);
    }

    public function testAssertViewHasRejectsACollectionContainingDifferentKeylessModels(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => new EloquentCollection([new TestModel, new TestModel])],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foos', new EloquentCollection([new TestModel, new TestModel]));
    }

    public function testAssertViewHasAcceptsACollectionWithTheSameStoredIdentities(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => [
                'foos' => new EloquentCollection([
                    new TestModel(['id' => 1]),
                    new TestModel(['id' => 2]),
                ]),
            ],
        ]);

        $response->assertViewHas('foos', new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]));
    }

    public function testAssertViewHasRejectsANonModelAsAnAssertionFailure(): void
    {
        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foo' => 'not a model'],
        ]);

        $this->expectException(AssertionFailedError::class);

        $response->assertViewHas('foo', new TestModel);
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

        $response = $this->makeMockResponse([
            'render' => 'hello world',
            'gatherData' => ['foos' => $actual],
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that the collection at [foos.2] matches the given collection.');

        $response->assertViewHas('foos', $expected);
    }

    public function testBinaryFileContentCanBeAssertedWithoutClassifyingTheResponseAsStreamed(): void
    {
        $response = TestResponse::fromBaseResponse(
            new BinaryFileResponse(__DIR__ . '/Fixtures/file.json')
        );

        $response->assertNotStreamed();
        $response->assertStreamedContent('{"foo":"bar"}');
        $response->assertStreamedJsonContent(['foo' => 'bar']);
    }

    public function testHeadStreamedContentDoesNotInvokeCallbackProducer(): void
    {
        $invocations = 0;
        $response = TestResponse::fromBaseResponse(
            new StreamedResponse(function () use (&$invocations): void {
                ++$invocations;

                echo 'content';
            }),
            Request::create('/', 'HEAD'),
        );

        $response->assertStreamedContent('');
        $this->assertSame(0, $invocations);
    }

    public function testHeadStreamedContentDoesNotInvokeIterableProducer(): void
    {
        $invocations = 0;
        $chunks = (function () use (&$invocations): iterable {
            ++$invocations;

            yield 'content';
        })();
        $response = TestResponse::fromBaseResponse(
            new IterableStreamedResponse($chunks),
            Request::create('/', 'HEAD'),
        );
        unset($chunks);

        $response->assertStreamedContent('');
        $this->assertSame(0, $invocations);
    }

    private function makeMockResponse(array $content): TestResponse
    {
        $response = new Response;
        $response->setContent(m::mock(View::class, $content));

        return TestResponse::fromBaseResponse($response);
    }
}

class TestModel extends Model
{
    protected array $guarded = [];
}

class AnotherTestModel extends Model
{
    protected array $guarded = [];
}
