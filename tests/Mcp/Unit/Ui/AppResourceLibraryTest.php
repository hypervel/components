<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Ui;

use Hypervel\Mcp\Server\Ui\Enums\Library;
use Hypervel\Tests\TestCase;

class AppResourceLibraryTest extends TestCase
{
    public function testReturnsCorrectDomainsForTailwind(): void
    {
        $this->assertSame(['https://cdn.tailwindcss.com'], Library::Tailwind->domains());
    }

    public function testReturnsCorrectDomainsForAlpine(): void
    {
        $this->assertSame(['https://cdn.jsdelivr.net'], Library::Alpine->domains());
    }

    public function testReturnsScriptTagsForTailwind(): void
    {
        $tags = Library::Tailwind->scriptTags();

        $this->assertCount(2, $tags);
        $this->assertStringContainsString('cdn.tailwindcss.com', $tags[0]);
        $this->assertStringContainsString('tailwind.config', $tags[1]);
    }

    public function testReturnsScriptTagsForAlpine(): void
    {
        $tags = Library::Alpine->scriptTags();

        $this->assertCount(2, $tags);
        $this->assertStringContainsString('x-cloak', $tags[0]);
        $this->assertStringContainsString('alpinejs', $tags[1]);
    }
}
