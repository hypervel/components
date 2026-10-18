<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Document;
use Hypervel\Tests\Ai\TestCase;

class FileProviderOptionsTest extends TestCase
{
    public function testArrayProviderOptionsResolveForTheGivenProvider(): void
    {
        $document = Document::fromString('Hello')->withProviderOptions(['purpose' => 'fine-tune']);

        $this->assertSame(['purpose' => 'fine-tune'], $document->providerOptions(Lab::OpenAI));
    }

    public function testClosureProviderOptionsResolvePerProvider(): void
    {
        $document = Document::fromString('Hello')->withProviderOptions(fn (Lab $provider): array => match ($provider) {
            Lab::OpenAI => ['purpose' => 'assistants'],
            default => [],
        });

        $this->assertSame(['purpose' => 'assistants'], $document->providerOptions(Lab::OpenAI));
        $this->assertSame([], $document->providerOptions(Lab::Anthropic));
    }

    public function testClosureProviderOptionsSurvivePhpSerialization(): void
    {
        $document = Document::fromString('Hello')->withProviderOptions(fn (Lab $provider): array => match ($provider) {
            Lab::OpenAI => ['purpose' => 'assistants'],
            default => [],
        });

        $restored = unserialize(serialize($document));

        $this->assertSame(['purpose' => 'assistants'], $restored->providerOptions(Lab::OpenAI));
    }

    public function testHeadersResolveSeparatelyFromProviderOptions(): void
    {
        $document = Document::fromString('Hello')
            ->withHeaders(fn (Lab $provider): array => ['X-Provider' => $provider->value])
            ->withProviderOptions(['purpose' => 'fine-tune']);

        $this->assertSame(['purpose' => 'fine-tune'], $document->providerOptions(Lab::OpenAI));
        $this->assertSame(['X-Provider' => 'openai'], $document->headers(Lab::OpenAI));
    }
}
