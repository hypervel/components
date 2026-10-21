<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Agents\SummarizeAgent;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Providers\OpenAiProvider;
use Hypervel\Support\Str;
use Hypervel\Tests\Ai\TestCase;

class SummarizeMacroTest extends TestCase
{
    public function testSummaryCanBeGeneratedFromStringableMacro(): void
    {
        SummarizeAgent::fake(['A short summary.']);

        $summary = Str::of('A very long piece of text that needs summarizing.')->summarize();

        $this->assertSame('A short summary.', $summary);

        SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'A very long piece of text that needs summarizing.'
            && str_contains($prompt->agent->instructions(), '3 sentences'));
    }

    public function testSummaryHonorsRequestedSentenceCount(): void
    {
        SummarizeAgent::fake();

        str('Some text.')->summarize(sentences: 1);

        SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), '1 sentence')
            && ! str_contains($prompt->agent->instructions(), '1 sentences'));
    }

    public function testSummaryUsesTheCheapestModelByDefault(): void
    {
        SummarizeAgent::fake();

        str('Some text.')->summarize();

        SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === $prompt->provider->cheapestTextModel());
    }

    public function testSummaryCanBeGeneratedFromStaticStrMacro(): void
    {
        SummarizeAgent::fake(['A short summary.']);

        $summary = Str::summarize('A very long piece of text that needs summarizing.', sentences: 4);

        $this->assertSame('A short summary.', $summary);

        SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'A very long piece of text that needs summarizing.'
            && str_contains($prompt->agent->instructions(), '4 sentences'));
    }

    public function testSummaryMacroPassesThroughOptions(): void
    {
        SummarizeAgent::fake();

        str('Some text.')->summarize(
            sentences: 4,
            provider: Lab::OpenAI,
            model: 'custom-model',
            timeout: 45,
        );

        SummarizeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Some text.'
            && $prompt->provider instanceof OpenAiProvider
            && $prompt->model === 'custom-model'
            && $prompt->timeout === 45);
    }
}
