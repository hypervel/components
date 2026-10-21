<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Classification;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Support\Str;
use Hypervel\Tests\Ai\TestCase;

class DecideMacroTest extends TestCase
{
    public function testDecisionCanBeMadeFromStringableMacro(): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt): array => [
            'decision' => new BooleanAnswer($prompt->contains('FREE MONEY') ? 0.95 : 0.05),
        ]);

        $this->assertTrue(Str::of('FREE MONEY NOW')->decide('Is this spam?'));
        $this->assertFalse(str('Lunch at noon?')->decide('Is this spam?'));

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('decision')
            && $prompt->contains('FREE MONEY'));
    }

    public function testDecisionHonorsTheGivenThreshold(): void
    {
        Classification::fake([['decision' => new BooleanAnswer(0.7)]]);

        $this->assertFalse(str('Maybe spam.')->decide('Is this spam?', threshold: 0.9));
    }

    public function testDecisionMacroPassesThroughCriteriaAndOptions(): void
    {
        Classification::fake();

        Str::decide(
            'Some text.',
            'Is this spam?',
            criteria: ['true' => 'Unsolicited bulk mail.'],
            provider: Lab::TypeSafe,
            model: 'custom-model',
            timeout: 45,
        );

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Some text.')
            && $prompt->model === 'custom-model'
            && $prompt->timeout === 45
            && $prompt->questions['decision']->criteria === ['true' => 'Unsolicited bulk mail.']);
    }
}
