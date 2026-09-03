<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Concerns\GlobalWrappingTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Testbench\TestCase;

class GlobalWrappingTest extends TestCase
{
    /**
     * Get package providers for the global wrapping test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Define the global wrapping key before the provider boots.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('data.wrap', 'payload');
    }

    /**
     * Test enabled transformations use the boot-built global wrapper.
     */
    public function testUsesGlobalWrapper(): void
    {
        $data = new GloballyWrappedData('value');

        $this->assertSame([
            'payload' => ['value' => 'value'],
        ], $data->transform(TransformationContextFactory::create()->withWrapping()));
    }

    /**
     * Test an object may disable the global wrapper.
     */
    public function testDisablesGlobalWrapper(): void
    {
        $data = (new GloballyWrappedData('value'))->withoutWrapping();

        $this->assertSame([
            'value' => 'value',
        ], $data->transform(TransformationContextFactory::create()->withWrapping()));
    }
}

class GloballyWrappedData extends Data
{
    public function __construct(public string $value)
    {
    }
}
