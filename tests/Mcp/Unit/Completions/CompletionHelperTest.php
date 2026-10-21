<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Completions;

use Hypervel\Mcp\Server\Completions\CompletionHelper;
use Hypervel\Tests\TestCase;

class CompletionHelperTest extends TestCase
{
    public function testFiltersItemsByPrefixCaseInsensitively(): void
    {
        $items = ['php', 'python', 'javascript', 'java'];

        $result = CompletionHelper::filterByPrefix($items, 'py');

        $this->assertSame(['python'], $result);
    }

    public function testReturnsAllItemsWhenPrefixIsEmpty(): void
    {
        $items = ['php', 'python', 'javascript'];

        $result = CompletionHelper::filterByPrefix($items, '');

        $this->assertSame($items, $result);
    }

    public function testHandlesCaseInsensitiveMatching(): void
    {
        $items = ['PHP', 'Python', 'JavaScript'];

        $result = CompletionHelper::filterByPrefix($items, 'py');

        $this->assertSame(['Python'], $result);
    }

    public function testReturnsEmptyArrayWhenNoMatches(): void
    {
        $items = ['php', 'python'];

        $result = CompletionHelper::filterByPrefix($items, 'rust');

        $this->assertSame([], $result);
    }
}
