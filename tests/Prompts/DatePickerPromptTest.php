<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Prompts\DatePickerPrompt;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Prompts\datepicker;

class DatePickerPromptTest extends TestCase
{
    public function testCanUseTheDatepickerAlongsideTheBuiltInDateFunction(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-07-24');

        $this->assertSame('2026-07-24', $result->format('Y-m-d'));
        $this->assertSame('1970', date('Y', 86400));
    }

    public function testRendersAnInlineDateInputByDefault(): void
    {
        Prompt::fake([Key::ENTER]);

        datepicker(label: 'Release date', default: '2026-07-24');

        Prompt::assertStrippedOutputContains('2026-07-24');
        Prompt::assertOutputContains("\e[7m2026\e[27m");
    }

    public function testShowsTheWeekdayOfTheDateBeingEdited(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, '2', Key::CTRL_C]);

        $prompt = new DatePickerPrompt(label: 'Release date', default: '2026-07-24');
        $prompt->prompt();

        $this->assertSame('2026-07-24', $prompt->date->format('Y-m-d'));
        Prompt::assertStrippedOutputContains('Friday');
        Prompt::assertStrippedOutputContains('Thursday');
    }

    public function testMovesInlineFocusWithoutChangingTheDate(): void
    {
        Prompt::fake([Key::LEFT, Key::RIGHT, Key::CTRL_F, Key::RIGHT, Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-07-24');

        $this->assertSame('2026-07-24', $result->format('Y-m-d'));
        Prompt::assertOutputContains("\e[7m07\e[27m");
        Prompt::assertOutputContains("\e[7m24\e[27m");
    }

    #[DataProvider('inlineSteps')]
    public function testStepsInlineDateSegmentsSafely(string $default, array $keys, string $expected): void
    {
        Prompt::fake([...$keys, Key::ENTER]);

        $result = datepicker(label: 'Release date', default: $default);

        $this->assertSame($expected, $result->format('Y-m-d'));
    }

    public static function inlineSteps(): array
    {
        return [
            'leap year' => ['2024-02-29', [Key::UP], '2025-02-28'],
            'short month' => ['2026-01-31', [Key::RIGHT, Key::UP], '2026-02-28'],
            'previous month' => ['2026-03-31', [Key::TAB, Key::DOWN], '2026-02-28'],
            'year boundary' => ['2026-12-31', [Key::SHIFT_TAB, Key::UP], '2027-01-01'],
            'tab wrap' => ['2026-07-24', [Key::TAB, Key::TAB, Key::TAB, Key::DOWN], '2025-07-24'],
        ];
    }

    public function testTypesAnInlineDateAndCorrectsASegmentWithBackspace(): void
    {
        Prompt::fake(['2027-13', Key::BACKSPACE, '2-25', Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-07-24');

        $this->assertSame('2027-12-25', $result->format('Y-m-d'));
    }

    #[DataProvider('invalidInlineEdits')]
    public function testRejectsInvalidInlineEditsBeforeMovingFocusOrSubmitting(array $keys, array $correction, string $error, string $expected): void
    {
        Prompt::fake([...$keys, Key::TAB, Key::ENTER, ...$correction, Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-02-24');

        $this->assertSame($expected, $result->format('Y-m-d'));
        Prompt::assertOutputContains($error);
    }

    public static function invalidInlineEdits(): array
    {
        return [
            'incomplete year' => [['20'], ['26'], 'Incomplete year.', '2026-02-24'],
            'zero month' => [[Key::TAB, '00'], [Key::BACKSPACE, '2'], 'Invalid date.', '2026-02-24'],
            'impossible day' => [[Key::TAB, Key::TAB, '30'], [Key::BACKSPACE, Key::BACKSPACE, '01'], 'Invalid date.', '2026-02-01'],
        ];
    }

    public function testClampsInlineSteppingButRejectsTypedDatesOutsideTheRange(): void
    {
        Prompt::fake([Key::SHIFT_TAB, Key::UP, '30', Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, '05', Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-07-24', max: '2026-07-25');

        $this->assertSame('2026-07-05', $result->format('Y-m-d'));
        Prompt::assertOutputContains('Must be on or before 2026-07-25.');
    }

    public function testPreservesTheTimezoneWhenTypingInlineSegments(): void
    {
        Prompt::fake(['2027-12-25', Key::ENTER]);

        $result = datepicker(label: 'Release date', default: new DateTimeImmutable('2026-07-24', new DateTimeZone('America/New_York')));

        $this->assertSame('2027-12-25 America/New_York', $result->format('Y-m-d e'));
    }

    public function testPreservesCustomAndEmptyHints(): void
    {
        Prompt::fake([Key::ENTER]);
        datepicker(label: 'Release date', default: '2026-07-24', hint: 'A custom hint.');
        Prompt::assertStrippedOutputContains('A custom hint.');

        Prompt::fake([Key::ENTER]);
        datepicker(label: 'Release date', default: '2026-07-24', hint: '');
        Prompt::assertStrippedOutputDoesntContain('Left/Right or Tab');
    }

    public function testIgnoresUnrelatedCharactersAndEscapeSequencesInInlineSegments(): void
    {
        Prompt::fake(['a!', Key::DELETE, "\e[1;5C", '2027', Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-07-24');

        $this->assertSame('2027-07-24', $result->format('Y-m-d'));
    }

    public function testValidatesAndTransformsTheCommittedInlineDate(): void
    {
        Prompt::fake(['2027-12-25', Key::ENTER]);

        $validated = null;
        $result = datepicker(
            label: 'Release date',
            default: '2026-07-24',
            transform: fn (DateTimeImmutable $value) => $value->format('Y-m-d'),
            validate: function (string $value) use (&$validated): ?string {
                $validated = $value;

                return null;
            },
        );

        $this->assertSame('2027-12-25', $result);
        $this->assertSame($result, $validated);
    }

    public function testDoesNotSubmitAnEmptiedInlineSegment(): void
    {
        Prompt::fake([Key::SHIFT_TAB, Key::BACKSPACE, Key::BACKSPACE, Key::ENTER, '25', Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-07-24');

        $this->assertSame('2026-07-25', $result->format('Y-m-d'));
        Prompt::assertOutputContains('Incomplete day.');
    }

    #[DataProvider('boundedMultiSegmentInputs')]
    public function testAllowsValidBoundedDatesToBeEnteredAcrossMultipleSegments(array $keys): void
    {
        Prompt::fake([...$keys, Key::ENTER]);

        $prompt = new DatePickerPrompt('Release date', default: '2026-12-31', min: '2026-12-01', max: '2027-01-31');
        $result = $prompt->prompt();

        $this->assertSame('submit', $prompt->state);
        $this->assertSame('2027-01-15', $result->format('Y-m-d'));
    }

    public static function boundedMultiSegmentInputs(): array
    {
        return [
            'tab' => [['2027', Key::TAB, '01', Key::TAB, '15']],
            'arrows' => [['2027', Key::RIGHT, '01', Key::RIGHT, '15']],
            'paste' => [['2027-01-15']],
        ];
    }

    #[DataProvider('invalidPastedDates')]
    public function testPreservesTheFirstInvalidSegmentInAPastedDate(string $input, string $error, string $buffer, string $display): void
    {
        Prompt::fake([$input, Key::ENTER, Key::CTRL_C]);

        $prompt = new DatePickerPrompt('Release date', default: '2026-07-24');
        $prompt->prompt();

        $this->assertSame('cancel', $prompt->state);
        $this->assertSame($buffer, $prompt->segmentBuffer);
        $this->assertSame($display, $prompt->formattedValue());
        Prompt::assertOutputContains($error);
    }

    public static function invalidPastedDates(): array
    {
        return [
            'invalid month' => ['2027-13-01', 'Invalid date.', '13', '2027-13-24'],
            'incomplete year' => ['20-12-25', 'Incomplete year.', '20', '20__-07-24'],
        ];
    }

    public function testStopsPastingAtTheFinalDateSegment(): void
    {
        Prompt::fake(['2027-12-25-01', Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-07-24');

        $this->assertSame('2027-12-25', $result->format('Y-m-d'));
    }

    public function testReturnsTheDefaultDateAsADateTimeImmutableAtMidnight(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = datepicker(
            label: 'When should the deploy run?',
            default: '2026-07-24',
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2026-07-24 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testAcceptsADateTimeInterfaceDefault(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = datepicker(
            label: 'When should the deploy run?',
            default: new DateTime('2026-07-24 15:30:00'),
        );

        $this->assertSame('2026-07-24 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testDefaultsToToday(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = datepicker(label: 'When should the deploy run?');

        $this->assertSame((new DateTimeImmutable('today'))->format('Y-m-d H:i:s'), $result->format('Y-m-d H:i:s'));
    }

    public function testTransformsValues(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = datepicker(
            label: 'When should the deploy run?',
            default: '2026-07-24',
            transform: fn (DateTimeImmutable $date) => $date->format('Y-m-d'),
        );

        $this->assertSame('2026-07-24', $result);
    }

    public function testValidates(): void
    {
        Prompt::fake([Key::ENTER, Key::TAB, Key::TAB, Key::DOWN, Key::ENTER]);

        $result = datepicker(
            label: 'When should the deploy run?',
            default: '2026-07-25',
            validate: fn (DateTimeImmutable $date) => $date->format('N') >= 6
                ? 'The deploy cannot run on a weekend.'
                : null,
        );

        $this->assertSame('2026-07-24', $result->format('Y-m-d'));

        Prompt::assertOutputContains('The deploy cannot run on a weekend.');
    }

    public function testRejectsAnInvalidDefaultString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not-a-date');

        datepicker(label: 'When should the deploy run?', default: 'not-a-date');
    }

    #[DataProvider('dateArguments')]
    public function testRejectsDateStringsThatOverflowIntoAnotherDay(string $argument): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date [2026-02-30] is not valid.');

        datepicker('When should the deploy run?', ...[$argument => '2026-02-30']);
    }

    public static function dateArguments(): array
    {
        return [['default'], ['min'], ['max']];
    }

    public function testCanBeCancelled(): void
    {
        Prompt::fake([Key::CTRL_C]);

        datepicker(label: 'When should the deploy run?', default: '2026-07-24');

        Prompt::assertOutputContains('Cancelled.');
    }

    public function testReturnsTheDefaultWhenNonInteractive(): void
    {
        Prompt::interactive(false);

        $result = datepicker(label: 'When should the deploy run?', default: '2026-07-24');

        $this->assertSame('2026-07-24', $result->format('Y-m-d'));
    }

    public function testReturnsNullWhenNonInteractiveWithoutADefault(): void
    {
        Prompt::interactive(false);

        $this->assertNull(datepicker(label: 'When should the deploy run?'));
    }

    public function testFailsWhenNonInteractiveAndRequiredWithoutADefault(): void
    {
        Prompt::interactive(false);

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        datepicker(label: 'When should the deploy run?', required: true);
    }

    public function testFailsWhenNonInteractiveWithADefaultOutsideOfTheRange(): void
    {
        Prompt::interactive(false);

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Must be on or after 2026-08-01.');

        datepicker(label: 'When should the deploy run?', default: '2026-07-24', min: '2026-08-01');
    }

    public function testRendersTheSubmittedDate(): void
    {
        Prompt::fake([Key::ENTER]);

        datepicker(label: 'When should the deploy run?', default: '2026-07-24');

        Prompt::assertStrippedOutputContains('2026-07-24');
    }

    public function testIgnoresNonDigitInput(): void
    {
        Prompt::fake(['a', '!', Key::ENTER]);

        $result = datepicker(label: 'When should the deploy run?', default: '2026-07-24');

        $this->assertSame('2026-07-24', $result->format('Y-m-d'));
    }

    public function testClampsDaySteppingToTheMinDate(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::DOWN, Key::DOWN, Key::ENTER]);

        $result = datepicker(
            label: 'When should the deploy run?',
            default: '2026-07-02',
            min: '2026-07-01',
        );

        $this->assertSame('2026-07-01', $result->format('Y-m-d'));
    }

    public function testClampsMonthSteppingToTheMaxDate(): void
    {
        Prompt::fake([Key::TAB, Key::UP, Key::ENTER]);

        $result = datepicker(
            label: 'When should the deploy run?',
            default: '2026-07-24',
            max: '2026-08-05',
        );

        $this->assertSame('2026-08-05', $result->format('Y-m-d'));
    }

    public function testClampsTheDefaultIntoTheRange(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = datepicker(
            label: 'When should the deploy run?',
            default: '2026-07-24',
            min: '2026-08-01',
        );

        $this->assertSame('2026-08-01', $result->format('Y-m-d'));
    }

    public function testRejectsAMinDateAfterTheMaxDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('min');

        datepicker(label: 'When should the deploy run?', min: '2026-07-31', max: '2026-07-01');
    }

    public function testSupportsCustomValidation(): void
    {
        Prompt::validateUsing(function (Prompt $prompt): ?string {
            $this->assertSame('When should the deploy run?', $prompt->label);
            $this->assertSame('weekday', $prompt->validate);

            return $prompt->validate === 'weekday' && $prompt->value()->format('N') >= 6
                ? 'The deploy cannot run on a weekend.'
                : null;
        });

        Prompt::fake([Key::ENTER, Key::TAB, Key::TAB, Key::DOWN, Key::ENTER]);

        $result = datepicker(
            label: 'When should the deploy run?',
            default: '2026-07-25',
            validate: 'weekday',
        );

        $this->assertSame('2026-07-24', $result->format('Y-m-d'));

        Prompt::assertOutputContains('The deploy cannot run on a weekend.');
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);

        DatePickerPrompt::fallbackUsing(function (DatePickerPrompt $prompt): DateTimeImmutable {
            $this->assertSame('When should the deploy run?', $prompt->label);

            return new DateTimeImmutable('2026-01-01');
        });

        $result = datepicker(label: 'When should the deploy run?');

        $this->assertSame('2026-01-01', $result->format('Y-m-d'));
    }

    #[DataProvider('fullSegmentInputs')]
    public function testAdvancesToTheNextSegmentOnceTheTypedSegmentIsFull(array $keys, string $expected): void
    {
        Prompt::fake([...$keys, Key::ENTER]);

        $result = datepicker(label: 'Release date', default: '2026-07-24');

        $this->assertSame($expected, $result->format('Y-m-d'));
    }

    public static function fullSegmentInputs(): array
    {
        return [
            'digits only' => [['20301225'], '2030-12-25'],
            'one key at a time' => [['2', '0', '3', '0', '1', '2', '2', '5'], '2030-12-25'],
            'separators after advancing' => [['2', '0', '3', '0', '-', '1', '2', '-', '2', '5'], '2030-12-25'],
            'tab after advancing' => [['2030', Key::TAB, '12', Key::TAB, '25'], '2030-12-25'],
        ];
    }
}
