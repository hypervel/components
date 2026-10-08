<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Tests\TestCase;

class MetaTest extends TestCase
{
    public function testMetaExtractsProviderAndModelFromResponse(): void
    {
        $meta = new Meta('openai', 'gpt-4o');

        $this->assertSame('openai', $meta->provider);
        $this->assertSame('gpt-4o', $meta->model);
    }

    public function testMetaAcceptsNullProviderAndModel(): void
    {
        $meta = new Meta;

        $this->assertNull($meta->provider);
        $this->assertNull($meta->model);
    }

    public function testMetaReturnsEmptyCitationsWhenNotProvided(): void
    {
        $meta = new Meta('openai', 'gpt-4o');

        $this->assertEmpty($meta->citations);
    }

    public function testMetaToArrayIncludesProviderModelAndCitations(): void
    {
        $meta = new Meta('openai', 'gpt-4o');

        $array = $meta->toArray();

        $this->assertSame('openai', $array['provider']);
        $this->assertSame('gpt-4o', $array['model']);
        $this->assertSame([], $array['citations']);
    }

    public function testMetaJsonSerializeReturnsToArray(): void
    {
        $meta = new Meta('anthropic', 'claude-3-opus');

        $json = $meta->jsonSerialize();

        $this->assertSame('anthropic', $json['provider']);
        $this->assertSame('claude-3-opus', $json['model']);
    }
}
