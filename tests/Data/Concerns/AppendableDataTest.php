<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Concerns;

use Hypervel\Contracts\Foundation\Application;
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
     * Test resources expose the same append behavior as data objects (AppendTest).
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
