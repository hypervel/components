<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Providers\Tools;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Container\Container;
use Hypervel\Tests\Ai\Fixtures\Tools\DeferredTool;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ToolSearchTest extends TestCase
{
    public function testResolvedToolsDoNotShareProviderOptions(): void
    {
        $first = Container::getInstance()->make(ToolSearch::class)
            ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);
        $second = Container::getInstance()->make(ToolSearch::class);

        $this->assertSame(['cache_control' => ['type' => 'ephemeral']], $first->providerOptions(Lab::Anthropic));
        $this->assertSame([], $second->providerOptions(Lab::Anthropic));
    }

    public function testWithToolsReturnsANewInstancePreservingTheStrategyAndProviderOptions(): void
    {
        $tool = new DeferredTool;
        $original = (new ToolSearch(strategy: 'bm25'))
            ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

        $updated = $original->withTools([$tool]);

        $this->assertNotSame($original, $updated);
        $this->assertSame([$tool], $updated->tools);
        $this->assertSame([], $original->tools);
        $this->assertSame('bm25', $updated->strategy);
        $this->assertSame(['cache_control' => ['type' => 'ephemeral']], $updated->providerOptions(Lab::Anthropic));
    }

    public function testCarriesProviderOptionsForASpecificProvider(): void
    {
        $search = (new ToolSearch)->withProviderOptions(
            fn (Lab $provider): ?array => $provider === Lab::Anthropic ? ['cache_control' => ['type' => 'ephemeral']] : null,
        );

        $this->assertSame(['cache_control' => ['type' => 'ephemeral']], $search->providerOptions(Lab::Anthropic));
        $this->assertSame([], $search->providerOptions(Lab::OpenAI));
    }

    public function testRejectsAnUnknownSearchStrategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tool search strategy [semantic]');

        new ToolSearch(strategy: 'semantic');
    }
}
