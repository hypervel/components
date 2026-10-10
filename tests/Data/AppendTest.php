<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Testbench\TestCase;

class AppendTest extends TestCase
{
    /**
     * Get package providers for the append test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanAppendDataViaMethodOverwriting(): void
    {
        $data = new class('Freek') extends Data {
            /**
             * Create a named data object.
             */
            public function __construct(public string $name)
            {
            }

            /**
             * Get the data appended to the output.
             */
            public function with(): array
            {
                return ['alt_name' => "{$this->name} from Spatie"];
            }
        };

        $this->assertSame([
            'name' => 'Freek',
            'alt_name' => 'Freek from Spatie',
        ], $data->toArray());
    }

    public function testCanAppendDataViaMethodOverwritingWithClosures(): void
    {
        $data = new class('Freek') extends Data {
            /**
             * Create a named data object.
             */
            public function __construct(public string $name)
            {
            }

            /**
             * Get the data appended to the output.
             */
            public function with(): array
            {
                return [
                    'alt_name' => static function (self $data): string {
                        return $data->name . ' from Spatie via closure';
                    },
                ];
            }
        };

        $this->assertSame([
            'name' => 'Freek',
            'alt_name' => 'Freek from Spatie via closure',
        ], $data->toArray());
    }

    public function testCanAppendDataViaMethodCall(): void
    {
        $data = new class('Freek') extends Data {
            /**
             * Create a named data object.
             */
            public function __construct(public string $name)
            {
            }
        };

        $transformed = $data->additional([
            'company' => 'Spatie',
            'alt_name' => fn (Data $data): string => "{$data->name} from Spatie",
        ])->toArray();

        $this->assertSame([
            'name' => 'Freek',
            'company' => 'Spatie',
            'alt_name' => 'Freek from Spatie',
        ], $transformed);
    }

    public function testWhenUsingAdditionalMethodAndWithMethodTheAdditionalMethodWillBePrioritized(): void
    {
        $data = new class('Freek') extends Data {
            /**
             * Create a named data object.
             */
            public function __construct(public string $name)
            {
            }

            /**
             * Get the data appended to the output.
             */
            public function with(): array
            {
                return [
                    'alt_name' => static function (self $data): string {
                        return $data->name . ' from Spatie via closure';
                    },
                ];
            }
        };

        $this->assertSame([
            'name' => 'Freek',
            'alt_name' => 'I m Freek from additional',
        ], $data->additional(['alt_name' => 'I m Freek from additional'])->toArray());
    }
}
