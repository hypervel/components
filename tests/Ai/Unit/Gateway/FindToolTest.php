<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\Concerns\InvokesTools;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Tests\Ai\Fixtures\Tools\DeferredTool;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\TestCase;

function toolFinder(): object
{
    return new class {
        use InvokesTools;

        /**
         * Find a tool by name.
         */
        public function find(string $name, array $tools): ?Tool
        {
            return $this->findTool($name, $tools);
        }
    };
}

class FindToolTest extends TestCase
{
    public function testFindsATopLevelToolByName(): void
    {
        $tool = new NonStrictTool;

        $this->assertSame($tool, toolFinder()->find('NonStrictTool', [$tool]));
    }

    public function testFindsAToolNestedInsideAToolSearchTool(): void
    {
        $nested = new DeferredTool;

        $found = toolFinder()->find('DeferredTool', [
            new NonStrictTool,
            new ToolSearch(tools: [$nested]),
        ]);

        $this->assertSame($nested, $found);
    }

    public function testReturnsNullWhenTheToolIsNotPresentAnywhere(): void
    {
        $found = toolFinder()->find('Missing', [
            new NonStrictTool,
            new ToolSearch(tools: [new DeferredTool]),
        ]);

        $this->assertNull($found);
    }
}
