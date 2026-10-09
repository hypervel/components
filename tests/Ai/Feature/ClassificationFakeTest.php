<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class ClassificationFakeTest extends TestCase
{
    public function testClassifyRejectsBlankState(): void
    {
        Classification::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A non-blank state is required to classify.');
        Classification::of('  ');
    }

    public function testClassifyRejectsMissingQuestions(): void
    {
        Classification::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one question is required to classify.');
        Classification::of('text')->classify();
    }

    public function testClassifyRejectsQuestionsWithoutStringKeys(): void
    {
        Classification::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Questions must be Question instances keyed by a string.');
        Classification::of('text')->questions([new Boolean('Urgent?')]);
    }

    public function testChoiceRequiresAtLeastTwoOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A choice question requires at least two options.');
        new Choice('Team?', ['billing' => null]);
    }

    public function testBooleanCriteriaMayOnlyDescribeTrueAndFalse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Boolean criteria may only describe the "true" and "false" cases.');
        new Boolean('Did the build succeed?', ['maybe' => 'unclear']);
    }

    public function testScoreRequiresAListOfAtLeastTwoLevels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A score question requires a list of at least two levels.');
        new Score('How mad?', ['low' => 'Calm', 'high' => 'Angry']);
    }

    public function testFakeGeneratesShapeValidAnswersForEveryQuestion(): void
    {
        Classification::fake();

        $response = Classification::of('text')->questions([
            'is_urgent' => new Boolean('Urgent?'),
            'department' => new Choice('Team?', ['billing' => null, 'technical' => null]),
            'frustration' => new Score('How mad?', ['Calm', 'Annoyed', 'Angry']),
        ])->classify();

        $this->assertInstanceOf(BooleanAnswer::class, $response['is_urgent']);
        $this->assertGreaterThanOrEqual(0.0, $response['is_urgent']->probability);
        $this->assertLessThanOrEqual(1.0, $response['is_urgent']->probability);
        $this->assertInstanceOf(ChoiceAnswer::class, $response['department']);
        $this->assertContains($response['department']->choice, ['billing', 'technical']);
        $this->assertSame(['billing', 'technical'], array_keys($response['department']->probabilities));
        $this->assertSame(1.0, round(array_sum($response['department']->probabilities), 2));
        $this->assertInstanceOf(ScoreAnswer::class, $response['frustration']);
        $this->assertGreaterThanOrEqual(0.0, $response['frustration']->score);
        $this->assertLessThanOrEqual(2.0, $response['frustration']->score);
        $this->assertSame(['Calm', 'Annoyed', 'Angry'], $response['frustration']->legend);
        $this->assertSame('typesafe', $response->meta->provider);
    }

    public function testFakeAnswersMayBeOverriddenPerQuestion(): void
    {
        Classification::fake([
            ['department' => new ChoiceAnswer('billing', ['billing' => 0.9, 'technical' => 0.1], 0.9)],
        ]);

        $response = Classification::of('text')->questions([
            'is_urgent' => new Boolean('Urgent?'),
            'department' => new Choice('Team?', ['billing' => null, 'technical' => null]),
        ])->classify();

        $this->assertSame('billing', $response['department']->choice);
        $this->assertInstanceOf(BooleanAnswer::class, $response['is_urgent']);
    }

    public function testCanFakeClassificationWithClosure(): void
    {
        Classification::fake(fn (ClassificationPrompt $prompt): array => [
            'is_urgent' => new BooleanAnswer($prompt->contains('ASAP') ? 1.0 : 0.0),
        ]);

        $urgent = Classification::of('Fix this ASAP')->question('is_urgent', new Boolean('Urgent?'))->classify();
        $calm = Classification::of('No rush')->question('is_urgent', new Boolean('Urgent?'))->classify();

        $this->assertTrue($urgent['is_urgent']->isTrue());
        $this->assertFalse($calm['is_urgent']->isTrue());
    }

    public function testCanAssertClassified(): void
    {
        Classification::fake();

        Classification::of(['body' => 'Hypervel is great'])->question('is_urgent', new Boolean('Urgent?'))->timeout(45)->classify(provider: Lab::TypeSafe);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Hypervel')
            && $prompt->asks('is_urgent')
            && $prompt->count() === 1
            && $prompt->timeout === 45);

        Classification::assertNotClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('department'));
    }

    public function testFakeRecordsTheAttachmentsAClassificationWasGiven(): void
    {
        Classification::fake();

        $image = Image::fromBase64(base64_encode('photo'), 'image/png');

        Classification::of('Inspect this.', [$image])->question('damaged', new Boolean('Damaged?'))->classify(provider: Lab::OpenAI);

        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->attachments === [$image]);
    }

    public function testFakesRejectAttachmentsForProvidersThatCannotClassifyThem(): void
    {
        Classification::fake();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Provider [typesafe] does not support classification attachments.');
        Classification::of('Inspect this.', [Image::fromBase64(base64_encode('photo'), 'image/png')])
            ->question('damaged', new Boolean('Damaged?'))
            ->classify(provider: Lab::TypeSafe);
    }

    public function testCanAssertNothingClassified(): void
    {
        Classification::fake();

        Classification::assertNothingClassified();
    }

    public function testCanPreventStrayClassifications(): void
    {
        Classification::fake()->preventStrayClassifications();

        $this->expectException(RuntimeException::class);
        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify();
    }

    public function testMissingAnswerThrows(): void
    {
        Classification::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No answer was returned for question [nope].');
        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify()->answer('nope');
    }

    public function testNonClassificationProvidersThrow(): void
    {
        Classification::fake();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not support classification');
        Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'anthropic');
    }

    public function testCustomQuestionsRequireAnExplicitFakeAnswer(): void
    {
        Classification::fake();

        $question = new class implements Question {
            /**
             * Get the question as a provider-neutral array.
             */
            public function toArray(): array
            {
                return ['type' => 'noul', 'instructions' => 'Is this urgent?'];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to generate a fake answer for question [' . $question::class . ']. Provide a fake answer instead.');

        Classification::of('Review this request')->question('urgent', $question)->classify();
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('Classification failed.');
        Classification::fake([fn (): never => throw $exception, ['urgent' => new BooleanAnswer(1.0)]]);

        try {
            Classification::of('First')->question('urgent', new Boolean('Urgent?'))->classify();
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertTrue(Classification::of('Second')->question('urgent', new Boolean('Urgent?'))->classify()->answer('urgent')->isTrue());
    }
}
