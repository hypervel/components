<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Concerns;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Resource;
use Hypervel\Testbench\TestCase;

class AppendableDataTest extends TestCase
{
    /**
     * Get package providers for the appendable data test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Test additional data may be declared by the data class.
     */
    public function testAppendsDataFromWithMethod(): void
    {
        $data = new class('Taylor') extends Data {
            public function __construct(public string $name)
            {
            }

            public function with(): array
            {
                return ['label' => "{$this->name} from Hypervel"];
            }
        };

        $this->assertSame([
            'name' => 'Taylor',
            'label' => 'Taylor from Hypervel',
        ], $data->toArray());
    }

    /**
     * Test additional method closures receive the current data object.
     */
    public function testResolvesWithMethodClosures(): void
    {
        $data = new class('Taylor') extends Data {
            public function __construct(public string $name)
            {
            }

            public function with(): array
            {
                return [
                    'label' => static fn (self $data): string => "{$data->name} from Hypervel",
                ];
            }
        };

        $this->assertSame([
            'name' => 'Taylor',
            'label' => 'Taylor from Hypervel',
        ], $data->toArray());
    }

    /**
     * Test additional data may be supplied fluently.
     */
    public function testAppendsDataFromAdditionalMethod(): void
    {
        $data = new class('Taylor') extends Data {
            public function __construct(public string $name)
            {
            }
        };

        $transformed = $data->additional([
            'company' => 'Hypervel',
            'label' => static fn (Data $data): string => "{$data->name} from Hypervel",
        ])->toArray();

        $this->assertSame([
            'name' => 'Taylor',
            'company' => 'Hypervel',
            'label' => 'Taylor from Hypervel',
        ], $transformed);
    }

    /**
     * Test fluent additional data takes precedence over class data.
     */
    public function testAdditionalMethodTakesPrecedenceOverWithMethod(): void
    {
        $data = new class('Taylor') extends Data {
            public function __construct(public string $name)
            {
            }

            public function with(): array
            {
                return ['label' => 'class'];
            }
        };

        $this->assertSame([
            'name' => 'Taylor',
            'label' => 'instance',
        ], $data->additional(['label' => 'instance'])->toArray());
    }

    /**
     * Test resources expose the same append behavior.
     */
    public function testResourceAppendsAdditionalData(): void
    {
        $resource = new class('Taylor') extends Resource {
            public function __construct(public string $name)
            {
            }
        };

        $this->assertSame([
            'name' => 'Taylor',
            'company' => 'Hypervel',
        ], $resource->additional(['company' => 'Hypervel'])->toArray());
    }
}
