<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Concerns\WrappableDataTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
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
     * Test default transformations remain unwrapped.
     */
    public function testDefaultTransformationsRemainUnwrapped(): void
    {
        $data = (new WrappingData('value'))->wrap('payload');

        $this->assertSame(['value' => 'value'], $data->toArray());
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
