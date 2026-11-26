<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Prompts\DateTimePickerPrompt;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Prompts\datetimepicker;

class DateTimePickerPromptTest extends TestCase
{
    public function testEdits12HourTimes(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, '12:05 AM', Key::ENTER]);

        $result = datetimepicker('Release', default: '2026-07-24 14:30', use12Hours: true);

        $this->assertSame('2026-07-24 00:05', $result->format('Y-m-d H:i'));
        Prompt::assertStrippedOutputContains('12:05 AM');
    }

    public function testValidates12HourDatetimeEditsAgainstFullInstantBounds(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, '09:30 AM', Key::ENTER, 'p', Key::ENTER]);

        $result = datetimepicker(
            'Release',
            default: '2026-07-24 14:30',
            min: '2026-07-24 12:00',
            max: '2026-07-24 22:00',
            use12Hours: true,
        );

        $this->assertSame('2026-07-24 21:30', $result->format('Y-m-d H:i'));
        Prompt::assertOutputContains('Must be on or after 2026-07-24 12:00 PM.');
    }

    public function testPastesAFull12HourDatetimeIncludingSeconds(): void
    {
        Prompt::fake(['2027-12-25 12:05:45 AM', Key::ENTER]);

        $result = datetimepicker('Release', default: '2026-07-24 14:30:10', withSeconds: true, use12Hours: true);

        $this->assertSame('2027-12-25 00:05:45', $result->format('Y-m-d H:i:s'));
        Prompt::assertStrippedOutputContains('2027-12-25 12:05:45 AM');
    }

    #[DataProvider('pastedPeriods')]
    public function testPreservesThePeriodWhenPastingDatetimeSecondsAtEitherPrecision(string $period, bool $withSeconds, string $expected): void
    {
        Prompt::fake(["2026-07-24 09:45:12 {$period}", Key::ENTER]);

        $result = datetimepicker('Release', default: $period === 'PM' ? '2026-07-24 02:30' : '2026-07-24 14:30', use12Hours: true, withSeconds: $withSeconds);

        $this->assertSame('2026-07-24 ' . $expected, $result->format('Y-m-d H:i:s'));
    }

    public static function pastedPeriods(): array
    {
        return [
            ['AM', false, '09:45:00'],
            ['PM', false, '21:45:00'],
            ['AM', true, '09:45:12'],
            ['PM', true, '21:45:12'],
        ];
    }

    public function testRendersASingleInlineDatetimeInputWithTheWeekday(): void
    {
        Prompt::fake([Key::ENTER]);

        datetimepicker(label: 'Release', default: '2026-07-24 14:30');

        Prompt::assertStrippedOutputContains('2026-07-24 14:30');
        Prompt::assertStrippedOutputContains('Friday');
    }

    public function testEditsEveryInlineDatetimeSegment(): void
    {
        Prompt::fake(['2027-12-25 09:45:12', Key::ENTER]);

        $result = datetimepicker(label: 'Release', default: '2026-07-24 14:30:10', withSeconds: true);

        $this->assertSame('2027-12-25 09:45:12', $result->format('Y-m-d H:i:s'));
    }

    public function testStepsAndWrapsInlineTimeSegmentsWithoutChangingTheDay(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::UP, Key::RIGHT, Key::DOWN, Key::ENTER]);

        $result = datetimepicker(label: 'Release', default: '2026-07-24 23:00');

        $this->assertSame('2026-07-24 00:59', $result->format('Y-m-d H:i'));
        Prompt::assertOutputContains("\e[7m59\e[27m");
    }

    public function testValidatesInlineTimeEditsAgainstTheFullInstant(): void
    {
        Prompt::fake([Key::SHIFT_TAB, '15', Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, '25', Key::ENTER]);

        $result = datetimepicker(label: 'Release', default: '2026-07-24 09:30', min: '2026-07-24 09:20');

        $this->assertSame('2026-07-24 09:25', $result->format('Y-m-d H:i'));
        Prompt::assertOutputContains('Must be on or after 2026-07-24 09:20.');
    }

    #[DataProvider('invalidTimeSegments')]
    public function testRejectsInvalidTimeSegmentsWithoutNormalizingThem(bool $withSeconds, array $keys, string $typed, string $correction): void
    {
        Prompt::fake([...$keys, $typed, Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, $correction, Key::ENTER]);

        $result = datetimepicker(label: 'Release', default: '2026-07-24 14:30:10', withSeconds: $withSeconds);

        $this->assertSame('2026-07-24', $result->format('Y-m-d'));
        Prompt::assertOutputContains('Invalid time.');
    }

    public static function invalidTimeSegments(): array
    {
        return [
            'hour' => [false, [Key::TAB, Key::TAB, Key::TAB], '24', '23'],
            'minute' => [false, [Key::TAB, Key::TAB, Key::TAB, Key::TAB], '60', '59'],
            'second' => [true, [Key::SHIFT_TAB], '60', '59'],
        ];
    }

    public function testClampsDatetimeDateSteppingToTheFullInstantBoundaries(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::DOWN, Key::ENTER]);

        $result = datetimepicker(label: 'Release', default: '2026-07-25 09:30', min: '2026-07-24 10:00');

        $this->assertSame('2026-07-24 10:00', $result->format('Y-m-d H:i'));
    }

    public function testWrapsInlineSecondsAndTabsBackToTheYear(): void
    {
        Prompt::fake([Key::SHIFT_TAB, Key::DOWN, Key::TAB, Key::UP, Key::ENTER]);

        $result = datetimepicker(label: 'Release', default: '2026-07-24 14:30:00', withSeconds: true);

        $this->assertSame('2027-07-24 14:30:59', $result->format('Y-m-d H:i:s'));
    }

    public function testPreservesDatetimeHints(): void
    {
        Prompt::fake([Key::ENTER]);
        datetimepicker(label: 'Release', default: '2026-07-24 14:30');
        Prompt::assertStrippedOutputContains('Left/Right or Tab: move.');

        Prompt::fake([Key::ENTER]);
        datetimepicker(label: 'Release', default: '2026-07-24 14:30', hint: 'Custom hint.');
        Prompt::assertStrippedOutputContains('Custom hint.');

        Prompt::fake([Key::ENTER]);
        datetimepicker(label: 'Release', default: '2026-07-24 14:30', hint: '');
        Prompt::assertStrippedOutputDoesntContain('Left/Right or Tab');
    }

    public function testAllowsValidBoundedTimesToBeEnteredAcrossMultipleSegments(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, '10', Key::RIGHT, '00', Key::ENTER]);

        $prompt = new DateTimePickerPrompt(
            'Release',
            default: '2026-07-24 09:30',
            min: '2026-07-24 09:30',
            max: '2026-07-24 10:15',
        );
        $result = $prompt->prompt();

        $this->assertSame('submit', $prompt->state);
        $this->assertSame('2026-07-24 10:00', $result->format('Y-m-d H:i'));
    }

    public function testPreservesTheInvalidTimeSegmentInAPastedDatetime(): void
    {
        Prompt::fake(['2027-12-25 24:01', Key::ENTER, Key::CTRL_C]);

        $prompt = new DateTimePickerPrompt('Release', default: '2026-07-24 14:30');
        $prompt->prompt();

        $this->assertSame('cancel', $prompt->state);
        $this->assertSame('hour', $prompt->focused);
        $this->assertSame('24', $prompt->segmentBuffer);
        $this->assertSame('2027-12-25 24:30', $prompt->formattedValue());
        Prompt::assertOutputContains('Invalid time.');
    }

    #[DataProvider('finalSegmentPastes')]
    public function testStopsPastingAtTheFinalTimeSegment(bool $withSeconds, string $input, string $expected): void
    {
        Prompt::fake([$input, Key::ENTER]);

        $result = datetimepicker(label: 'Release', default: '2026-07-24 14:30:10', withSeconds: $withSeconds);

        $this->assertSame($expected, $result->format('Y-m-d H:i:s'));
    }

    public static function finalSegmentPastes(): array
    {
        return [
            'minutes' => [false, '2027-12-25 09:45:12', '2027-12-25 09:45:00'],
            'seconds' => [true, '2027-12-25 09:45:12:59', '2027-12-25 09:45:12'],
        ];
    }

    public function testValidatesAndTransformsADateCorrectedThroughItsTime(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, '24', Key::ENTER, '10', Key::ENTER]);

        $validated = null;
        $prompt = new DateTimePickerPrompt(
            'Release',
            default: '2026-07-25 09:30',
            min: '2026-07-24 10:00',
            max: '2026-07-25 09:45',
            transform: fn (DateTimeImmutable $value) => $value->format('Y-m-d H:i'),
            validate: function (string $value) use (&$validated): ?string {
                $validated = $value;

                return null;
            },
        );
        $result = $prompt->prompt();

        $this->assertSame('submit', $prompt->state);
        $this->assertSame('2026-07-24 10:30', $result);
        $this->assertSame($result, $validated);
        Prompt::assertOutputContains('Must be on or after 2026-07-24 10:00.');
    }

    public function testReturnsTheDefaultDatetimeWithTheSecondsZeroed(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = datetimepicker(
            label: 'When should the deploy run?',
            default: '2026-07-24 14:30:45',
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2026-07-24 14:30:00', $result->format('Y-m-d H:i:s'));
    }

    public function testEditsTheHourWithTheArrowKeysAfterTabbing(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::UP, Key::ENTER]);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 14:30');

        $this->assertSame('2026-07-24 15:30', $result->format('Y-m-d H:i'));
    }

    public function testWrapsTheHourAroundMidnight(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::UP, Key::ENTER]);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 23:30');

        $this->assertSame('00:30', $result->format('H:i'));

        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::DOWN, Key::ENTER]);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 00:15');

        $this->assertSame('23:15', $result->format('H:i'));
    }

    public function testReplacesTheSelectedTimeSegmentWithTypedDigits(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, '0', '9', Key::ENTER]);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 14:30');

        $this->assertSame('09:30', $result->format('H:i'));

        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::TAB, '3', '7', Key::ENTER]);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 14:59');

        $this->assertSame('14:37', $result->format('H:i'));
    }

    public function testIgnoresEscapeSequencesWhileATimeSegmentIsFocused(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::PAGE_UP, Key::SHIFT_UP, Key::ENTER]);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 14:30');

        $this->assertSame('2026-07-24 14:30', $result->format('Y-m-d H:i'));
    }

    public function testMovesBetweenTimeSegmentsWithTheArrowKeys(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::RIGHT, '4', '5', Key::ENTER]);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 14:30');

        $this->assertSame('14:45', $result->format('H:i'));
    }

    public function testWrapsToTheLastTimeSegmentWithShiftTab(): void
    {
        Prompt::fake([Key::SHIFT_TAB, '4', '5', Key::ENTER]);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 14:30');

        $this->assertSame('14:45', $result->format('H:i'));
    }

    public function testSupportsASecondsSegment(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::TAB, Key::TAB, Key::UP, Key::ENTER]);

        $result = datetimepicker(
            label: 'When should the deploy run?',
            default: '2026-07-24 14:30:10',
            withSeconds: true,
        );

        $this->assertSame('2026-07-24 14:30:11', $result->format('Y-m-d H:i:s'));
    }

    public function testHighlightsTheFocusedTimeSegment(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::ENTER]);

        datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 14:30');

        Prompt::assertStrippedOutputContains('2026-07-24 14:30');
        Prompt::assertOutputContains("\e[7m14\e[27m");
    }

    public function testClampsSteppedTimesToTheRange(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::TAB, Key::DOWN, Key::ENTER]);

        $result = datetimepicker(
            label: 'When should the deploy run?',
            default: '2026-07-24 09:30',
            min: '2026-07-24 09:00',
        );

        $this->assertSame('2026-07-24 09:00', $result->format('Y-m-d H:i'));
    }

    public function testIgnoresMicrosecondsInTheRangeBoundaries(): void
    {
        Prompt::interactive(false);

        $result = datetimepicker(
            label: 'When should the maintenance window start?',
            default: '2026-07-24 09:00:00.900000',
            min: '2026-07-24 09:00:00.500000',
            withSeconds: true,
        );

        $this->assertSame('2026-07-24 09:00:00.000000', $result->format('Y-m-d H:i:s.u'));
    }

    public function testReturnsTheDefaultWhenNonInteractive(): void
    {
        Prompt::interactive(false);

        $result = datetimepicker(label: 'When should the deploy run?', default: '2026-07-24 14:30');

        $this->assertSame('2026-07-24 14:30', $result->format('Y-m-d H:i'));
    }

    public function testReturnsNullWhenNonInteractiveWithoutADefault(): void
    {
        Prompt::interactive(false);

        $this->assertNull(datetimepicker(label: 'When should the deploy run?'));
    }

    #[DataProvider('daylightSavingGapInputs')]
    public function testRejectsTypedTimesInADaylightSavingGap(bool $use12Hours, string $default, array $keys, string $focused, string $buffer): void
    {
        Prompt::fake([...$keys, Key::ENTER, Key::CTRL_C]);

        $prompt = new DateTimePickerPrompt('Release', default: new DateTimeImmutable($default, new DateTimeZone('America/New_York')), use12Hours: $use12Hours);
        $prompt->prompt();

        $this->assertSame('cancel', $prompt->state);
        $this->assertSame($focused, $prompt->focused);
        $this->assertSame($buffer, $prompt->segmentBuffer);
        $this->assertNotSame('03:15', $prompt->value()->format('H:i'));
        Prompt::assertOutputContains('Invalid time.');
    }

    public static function daylightSavingGapInputs(): array
    {
        return [
            '24h hour' => [false, '2026-03-08 01:30', [Key::TAB, Key::TAB, Key::TAB, '02:15'], 'hour', '02'],
            '12h period' => [true, '2026-03-08 14:15', [Key::SHIFT_TAB, 'a'], 'period', 'AM'],
        ];
    }

    #[DataProvider('daylightSavingGapSteps')]
    public function testDoesNotStepIntoADaylightSavingGap(bool $use12Hours, string $default, array $keys): void
    {
        Prompt::fake([...$keys, Key::ENTER]);

        $result = datetimepicker('Release', default: new DateTimeImmutable($default, new DateTimeZone('America/New_York')), use12Hours: $use12Hours);

        $this->assertSame($default, $result->format('Y-m-d H:i'));
        Prompt::assertOutputContains('Invalid time.');
    }

    public static function daylightSavingGapSteps(): array
    {
        return [
            '24h hour' => [false, '2026-03-08 01:30', [Key::TAB, Key::TAB, Key::TAB, Key::UP]],
            '12h hour' => [true, '2026-03-08 01:30', [Key::TAB, Key::TAB, Key::TAB, Key::UP]],
            '12h period' => [true, '2026-03-08 14:15', [Key::SHIFT_TAB, Key::UP]],
        ];
    }

    public function testShowsTheShiftedTimeWhenSteppingTheDateOntoADaylightSavingGap(): void
    {
        Prompt::fake([Key::TAB, Key::TAB, Key::UP, Key::ENTER]);

        $result = datetimepicker('Release', default: new DateTimeImmutable('2026-03-07 02:30', new DateTimeZone('America/New_York')));

        $this->assertSame('2026-03-08 03:30', $result->format('Y-m-d H:i'));
        Prompt::assertStrippedOutputContains('2026-03-08 03:30');
    }

    #[DataProvider('boundedTimeSteps')]
    public function testClampsDatetimeTimeSteppingToTheFullInstantBoundaries(array $keys, string $expected): void
    {
        Prompt::fake([...$keys, Key::CTRL_C]);

        $prompt = new DateTimePickerPrompt('Release', default: '2026-07-24 12:30', min: '2026-07-24 12:30', max: '2026-07-24 13:00');
        $prompt->prompt();

        $this->assertSame($expected, $prompt->value()->format('Y-m-d H:i'));
    }

    public static function boundedTimeSteps(): array
    {
        return [
            'hour below min' => [[Key::TAB, Key::TAB, Key::TAB, Key::DOWN], '2026-07-24 12:30'],
            'minute below min' => [[Key::TAB, Key::TAB, Key::TAB, Key::TAB, Key::DOWN], '2026-07-24 12:30'],
            'hour above max' => [[Key::TAB, Key::TAB, Key::TAB, Key::UP], '2026-07-24 13:00'],
        ];
    }

    public function testFailsWhenNonInteractiveWithADefaultTimeOutsideOfTheRange(): void
    {
        Prompt::interactive(false);

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Must be on or after 2026-07-24 09:00.');

        datetimepicker(label: 'Release', default: '2026-07-24 08:00', min: '2026-07-24 09:00');
    }
}
