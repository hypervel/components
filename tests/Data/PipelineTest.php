<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\PipelineTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Support\Arr;
use Hypervel\Testbench\TestCase;

class PipelineTest extends TestCase
{
    // REMOVED: 'can prepend a data pipe at the beginning of the pipeline', 'replaces an existing pipe with a new one' and 'does not replace a non-existing pipe'; the configurable DataPipeline is not included.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanRestructurePayloadBeforeEnteringThePipeline(): void
    {
        $class = new class extends Data {
            /**
             * Create the data object from a name and an address.
             */
            public function __construct(
                public ?string $name = null,
                public ?string $address = null,
            ) {
            }

            /**
             * Join the flat address fields into one address.
             */
            public static function prepareForPipeline(array $properties): array
            {
                $properties['address'] = implode(',', Arr::only($properties, ['line_1', 'city', 'state', 'zipcode']));

                return $properties;
            }
        };

        $instance = $class::from([
            'name' => 'Freek',
            'line_1' => '123 Sesame St',
            'city' => 'New York',
            'state' => 'NJ',
            'zipcode' => '10010',
        ]);

        $this->assertSame(
            ['name' => 'Freek', 'address' => '123 Sesame St,New York,NJ,10010'],
            $instance->toArray(),
        );
    }
}
