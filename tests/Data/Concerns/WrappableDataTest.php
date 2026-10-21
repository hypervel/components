<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Concerns\WrappableDataTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;

abstract class WrappingTestCase extends TestCase
{
    /**
     * Get package providers for the wrapping test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }
}

class WrappableDataTest extends WrappingTestCase
{
    /**
     * Test a wrapped data collection on the returned object keeps its own wrapper, unlike one inside a collection
     * item or toArray() output.
     */
    public function testWrapsNestedDataCollectionsInResponses(): void
    {
        $songs = (new DataCollection(WrappingData::class, [new WrappingData('song')]))->wrap('data');
        $album = (new WrappingAlbumData('Album', $songs))->wrap('data');

        $this->assertSame([
            'data' => [
                'title' => 'Album',
                'songs' => ['data' => [['value' => 'song']]],
            ],
        ], $album->toResponse(Request::create('/'))->getData(true));
        $this->assertSame([
            ['title' => 'Album', 'songs' => [['value' => 'song']]],
        ], (new DataCollection(WrappingAlbumData::class, [$album]))->toResponse(Request::create('/'))->getData(true));
        $this->assertSame([
            'title' => 'Album',
            'songs' => [['value' => 'song']],
        ], $album->toArray());
    }

    /**
     * Test wrapping may be enabled for a transformation.
     */
    public function testWrapsAnEnabledTransformation(): void
    {
        $data = (new WrappingData('value'))->wrap('payload');

        $this->assertSame([
            'payload' => ['value' => 'value'],
        ], $data->transform(TransformationContextFactory::create()->withWrapping()));
    }

    /**
     * Test nested data remains unwrapped within a wrapped root.
     */
    public function testLeavesNestedDataUnwrapped(): void
    {
        $data = (new NestedWrappingData(
            (new WrappingData('nested'))->wrap('ignored'),
        ))->wrap('payload');

        $this->assertSame([
            'payload' => [
                'nested' => ['value' => 'nested'],
            ],
        ], $data->transform(TransformationContextFactory::create()->withWrapping()));
    }

    /**
     * Test additional data remains outside the root wrapper.
     */
    public function testAppendsAdditionalDataOutsideWrapper(): void
    {
        $data = (new WrappingData('value'))
            ->wrap('payload')
            ->additional(['meta' => 'data']);

        $this->assertSame([
            'payload' => ['value' => 'value'],
            'meta' => 'data',
        ], $data->transform(TransformationContextFactory::create()->withWrapping()));
    }
}

class WrappingData extends Data
{
    public function __construct(public string $value)
    {
    }
}

class NestedWrappingData extends Data
{
    public function __construct(public WrappingData $nested)
    {
    }
}

class WrappingAlbumData extends Data
{
    /**
     * Create an album with a nested song collection.
     */
    public function __construct(
        public string $title,
        #[DataCollectionOf(WrappingData::class)]
        public DataCollection $songs,
    ) {
    }
}
