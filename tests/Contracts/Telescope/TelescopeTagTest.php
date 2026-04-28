<?php

declare(strict_types=1);

namespace Hypervel\Tests\Contracts\Telescope;

use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Tests\TestCase;

class TelescopeTagTest extends TestCase
{
    public function testCasesMapToExpectedWireValues()
    {
        // Locks in the string values used at the wire level. Changing any of
        // these would be a breaking change for consumers (MCP servers,
        // dashboards, users filtering by tag).
        $this->assertSame('scout', TelescopeTag::Scout->value);
        $this->assertSame('algolia', TelescopeTag::Algolia->value);
        $this->assertSame('meilisearch', TelescopeTag::Meilisearch->value);
        $this->assertSame('typesense', TelescopeTag::Typesense->value);
    }

    public function testAllCasesHaveNonEmptyDescriptions()
    {
        foreach (TelescopeTag::cases() as $case) {
            $description = $case->description();

            $this->assertNotSame('', $description, "Case {$case->name} has an empty description.");
        }
    }
}
