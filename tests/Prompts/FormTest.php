<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use DateTimeImmutable;
use Hypervel\Prompts\DatePickerPrompt;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Prompts\confirm;
use function Hypervel\Prompts\form;
use function Hypervel\Prompts\outro;
use function Hypervel\Prompts\text;

class FormTest extends TestCase
{
    public function testCanRunMultipleSteps(): void
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame([
            'Luke',
            'PHP',
            true,
        ], $responses);
    }

    public function testCanRevertSteps(): void
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::CTRL_U,
            Key::CTRL_U,
            ...array_fill(0, 4, Key::BACKSPACE),
            'J',
            'e',
            's',
            's',
            Key::ENTER,
            Key::DOWN,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame([
            'Jess',
            'JS',
            true,
        ], $responses);
    }

    public function testTtyRestoreFailureDoesNotPreventFormStepReversion(): void
    {
        Prompt::fake([
            'L',
            Key::ENTER,
            Key::CTRL_U,
            Key::ENTER,
            Key::ENTER,
        ]);
        $restoreCalls = 0;

        Prompt::terminal()
            ->shouldReceive('restoreTty') // @phpstan-ignore-line
            ->andReturnUsing(function () use (&$restoreCalls): void {
                ++$restoreCalls;

                if ($restoreCalls === 2) {
                    throw new RuntimeException('terminal restoration failed');
                }
            });

        $responses = form()
            ->text('What is your name?')
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame(['L', true], $responses);
        $this->assertSame(4, $restoreCalls);
    }

    public function testPassesAllAvailableResponsesToEachStep(): void
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->add(fn ($responses) => confirm("Are you sure your name is {$responses[0]} and your language is {$responses[1]}?"))
            ->submit();

        Prompt::assertOutputContains('Are you sure your name is Luke and your language is PHP?');
    }

    public function testCanKeyAResponseByAGivenString(): void
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?', name: 'name')
            ->select('What is your language?', ['PHP', 'JS'], name: 'language')
            ->add(fn ($responses) => confirm("Are you sure your name is {$responses['name']} and your language is {$responses['language']}?"))
            ->submit();

        Prompt::assertOutputContains('Are you sure your name is Luke and your language is PHP?');
    }

    public function testTextareaAcceptsValidationRules(): void
    {
        Prompt::fake(['H', 'i', Key::CTRL_D]);

        $rules = null;
        Prompt::validateUsing(function (Prompt $prompt) use (&$rules): null {
            $rules = $prompt->validate;

            return null;
        });

        $responses = form()
            ->textarea('Story', validate: ['required', 'max:100'])
            ->submit();

        $this->assertSame(['Hi'], $responses);
        $this->assertSame(['required', 'max:100'], $rules);
    }

    public function testDoesNotAllowRevertingNormalPrompts(): void
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::ENTER,
            Key::CTRL_U,
            Key::ENTER,
        ]);

        form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->submit();

        $confirm = confirm('Are you sure?');

        Prompt::assertOutputContains('This cannot be reverted.');
        $this->assertTrue($confirm);
    }

    public function testClearsRevertHandlerWhenFormFails(): void
    {
        Prompt::fake([Key::CTRL_U, Key::ENTER]);

        try {
            form()
                ->add(static fn (): string => 'first')
                ->add(static fn () => throw new RuntimeException('Form failed.'))
                ->submit();

            $this->fail('Expected the form to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Form failed.', $exception->getMessage());
        }

        $this->assertTrue(confirm('Are you sure?'));
        Prompt::assertOutputContains('This cannot be reverted.');
    }

    public function testDoesNotAllowRevertingTheFirstStep(): void
    {
        Prompt::fake([Key::CTRL_U, Key::ENTER]);

        $responses = form()->confirm('Are you sure?')->submit();

        $this->assertSame([true], $responses);
    }

    public function testSkipStepsOverStepsThatHaveNoUserInputWhenReverting(): void
    {
        Prompt::fake([
            '3',
            Key::ENTER,
            Key::CTRL_U,
            '0',
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('How old are you?')
            ->info('This should be skipped')
            ->alert('This should be skipped')
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame(['30', null, null, true], $responses);
    }

    public function testWillNotSkipOverTheFirstStepWhenReverting(): void
    {
        Prompt::fake([
            Key::CTRL_U,
            Key::ENTER,
        ]);

        $responses = form()
            ->info('This should not be skipped')
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame([null, true], $responses);
    }

    public function testPrefillsExistingResponsesWhenReverting(): void
    {
        Prompt::fake([
            'J',
            'e',
            's',
            's',
            Key::ENTER,
            Key::CTRL_U,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame('Jess', $responses[0]);
    }

    public function testRevertingAStepWhoseTransformChangesTheTypeEditsTheAcceptedAnswer(): void
    {
        Prompt::fake([
            Key::ENTER,
            Key::CTRL_U,
            Key::SHIFT_TAB, Key::UP, Key::ENTER,
            Key::CTRL_U,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->datepicker(
                'When should the deploy run?',
                default: '2026-07-24',
                transform: fn (DateTimeImmutable $date) => $date->format('d') === '25' ? null : $date->getTimestamp(),
            )
            ->confirm('Are you sure?')
            ->submit();

        // The second revert edits July 25, though its transformed response is null.
        $this->assertSame([null, true], $responses);
    }

    public function testRevertingATransformedFallbackStepEditsItsAnswerOrReopensWithItsDefault(): void
    {
        $defaults = [];
        DatePickerPrompt::fallbackUsing(function (DatePickerPrompt $prompt) use (&$defaults): mixed {
            $defaults[] = $prompt->default?->format('Y-m-d');

            // The first response is returned directly, the next ones through the transform.
            return count($defaults) === 1
                ? strtotime('2026-07-25')
                : ($prompt->transform)(new DateTimeImmutable('2026-07-26'));
        });
        Prompt::fallbackWhen(true);
        Prompt::fake([Key::CTRL_U, Key::CTRL_U, Key::ENTER]);

        $responses = form()
            ->datepicker('When should the deploy run?', default: '2026-07-24', transform: fn (DateTimeImmutable $date) => $date->getTimestamp())
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame(['2026-07-24', '2026-07-24', '2026-07-26'], $defaults);
        $this->assertSame([strtotime('2026-07-26'), true], $responses);
    }

    public function testRevertingATransformedTextStepEditsADirectFallbackResponse(): void
    {
        $defaults = [];
        TextPrompt::fallbackUsing(function (TextPrompt $prompt) use (&$defaults): string {
            $defaults[] = $prompt->default;

            return 'alice@example.com';
        });
        Prompt::fallbackWhen(true);
        Prompt::fake([Key::CTRL_U, Key::ENTER]);

        $responses = form()
            ->text('What is your email?', default: 'old@example.com', transform: trim(...))
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame(['old@example.com', 'alice@example.com'], $defaults);
        $this->assertSame(['alice@example.com', true], $responses);
    }

    public function testRevertingACanceledStepEditsTheCancelResponseRatherThanRejectedInput(): void
    {
        Prompt::cancelUsing(fn (): string => '');
        Prompt::fake([
            ...str_split('draft'), Key::ENTER,
            Key::CTRL_C,
            Key::CTRL_U,
            ...str_split('alice@example.com'), Key::ENTER,
            Key::ENTER,
        ]);

        // The rejected input and the cancel handler both produce an empty response.
        $responses = form()
            ->text('What is your email?', required: true, transform: fn (string $email): string => filter_var($email, FILTER_VALIDATE_EMAIL) ?: '')
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame(['alice@example.com', true], $responses);
    }

    public function testStopStepsAtTheMomentOfReverting(): void
    {
        Prompt::fake([
            '2',
            '7',
            Key::ENTER,
            Key::DOWN,
            Key::CTRL_U,
            Key::ENTER,
            Key::ENTER,
        ]);

        form()
            ->text('What is your age?')
            ->add(function () {
                $confirmed = confirm('Are you sure?');

                if (! $confirmed) {
                    outro('This should not appear!');
                }
            })->submit();

        Prompt::assertOutputDoesntContain('This should not appear!');
    }

    public function testCanRevertStepsWithConditions(): void
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::DOWN,
            Key::ENTER,
            Key::CTRL_U,
            Key::UP,
            Key::ENTER,
            '8',
            '.',
            '3',
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->addIf(fn ($responses) => $responses[1] === 'PHP', fn ($responses) => text('Which version?'))
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame([
            'Luke',
            'PHP',
            '8.3',
            true,
        ], $responses);
    }

    public function testLeavesSkippedConditionalFieldEmpty(): void
    {
        Prompt::fake([
            'L',
            'u',
            'k',
            'e',
            Key::ENTER,
            Key::DOWN,
            Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->text('What is your name?')
            ->select('What is your language?', ['PHP', 'JS'])
            ->addIf(fn ($responses) => $responses[1] === 'PHP', fn ($responses) => text('Which version?'))
            ->confirm('Are you sure?')
            ->submit();

        $this->assertSame([
            'Luke',
            'JS',
            null,
            true,
        ], $responses);
    }

    public function testSupportsTimepickerAnd12HourDatetimeSteps(): void
    {
        Prompt::fake(['09:45:12 PM', Key::ENTER, Key::SHIFT_TAB, 'a', Key::ENTER]);

        $responses = form()
            ->timepicker('Start time', default: '14:30', withSeconds: true, use12Hours: true, name: 'start')
            ->datetimepicker('Release', default: '2026-07-24 14:30', use12Hours: true, name: 'release')
            ->submit();

        $this->assertSame('21:45:12', $responses['start']->format('H:i:s'));
        $this->assertSame('2026-07-24 02:30', $responses['release']->format('Y-m-d H:i'));
    }

    public function testReusesThePreviousTimeResponseWhenReverting(): void
    {
        Prompt::fake(['09:45', Key::ENTER, Key::CTRL_U, Key::ENTER, Key::ENTER]);

        $responses = form()
            ->timepicker('Start time', default: '14:30')
            ->confirm('Continue?')
            ->submit();

        $this->assertSame('09:45', $responses[0]->format('H:i'));
    }

    public function testSupportsDatepickerAndDatetimepickerSteps(): void
    {
        Prompt::fake([
            Key::ENTER,
            Key::TAB, Key::TAB, Key::TAB, Key::UP, Key::ENTER,
        ]);

        $responses = form()
            ->datepicker('When should the deploy run?', default: '2026-07-24', name: 'date')
            ->datetimepicker('When should the maintenance window start?', default: '2026-07-24 14:30', name: 'window')
            ->submit();

        $this->assertSame('2026-07-24', $responses['date']->format('Y-m-d'));
        $this->assertSame('2026-07-24 15:30', $responses['window']->format('Y-m-d H:i'));
    }

    public function testReusesThePreviousDateResponseAsTheDefaultWhenReverting(): void
    {
        Prompt::fake([
            Key::ENTER,
            Key::CTRL_U,
            Key::SHIFT_TAB, Key::UP, Key::ENTER,
            Key::ENTER,
        ]);

        $responses = form()
            ->datepicker('When should the deploy run?', default: '2026-07-24')
            ->datetimepicker('When should the maintenance window start?', default: '2026-07-24 14:30')
            ->submit();

        $this->assertSame('2026-07-25', $responses[0]->format('Y-m-d'));
        $this->assertSame('2026-07-24 14:30', $responses[1]->format('Y-m-d H:i'));
    }
}
