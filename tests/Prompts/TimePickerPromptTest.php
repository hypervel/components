<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\TimePickerPrompt;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Prompts\timepicker;

class TimePickerPromptTest extends TestCase
{
    public function testRendersAnInline24HourTimeByDefault(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = timepicker('Start time', default: '14:30:45');

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('14:30:00', $result->format('H:i:s'));
        Prompt::assertStrippedOutputContains('14:30');
        Prompt::assertStrippedOutputDoesntContain('PM');
    }

    #[DataProvider('wrappedSegmentEdits')]
    public function testEditsAndWrapsTimeSegmentsWithoutCarrying(array $keys, string $expected): void
    {
        Prompt::fake([...$keys, Key::ENTER]);

        $result = timepicker('Start time', default: '23:00:00', withSeconds: true);

        $this->assertSame($expected, $result->format('H:i:s'));
    }

    public static function wrappedSegmentEdits(): array
    {
        return [
            'hour' => [[Key::UP], '00:00:00'],
            'minute' => [[Key::RIGHT, Key::DOWN], '23:59:00'],
            'second' => [[Key::SHIFT_TAB, Key::DOWN], '23:00:59'],
            'paste' => [['09:45:12'], '09:45:12'],
        ];
    }

    #[DataProvider('twelveHourDisplays')]
    public function testRendersMidnightAndNoonIn12HourMode(string $default, string $display): void
    {
        Prompt::fake([Key::ENTER]);

        timepicker('Start time', default: $default, use12Hours: true);

        Prompt::assertStrippedOutputContains($display);
    }

    public static function twelveHourDisplays(): array
    {
        return [
            ['00:00', '12:00 AM'],
            ['12:00', '12:00 PM'],
            ['23:30', '11:30 PM'],
        ];
    }

    #[DataProvider('twelveHourEdits')]
    public function testEdits12HourTimesAndTheirPeriod(array $keys, string $expected): void
    {
        Prompt::fake([...$keys, Key::ENTER]);

        $result = timepicker('Start time', default: '14:30', use12Hours: true);

        $this->assertSame($expected, $result->format('H:i'));
    }

    public static function twelveHourEdits(): array
    {
        return [
            'paste AM' => [['12:05 am'], '00:05'],
            'paste PM' => [['01:45 PM'], '13:45'],
            'toggle' => [[Key::SHIFT_TAB, Key::UP], '02:30'],
            'type period' => [[Key::SHIFT_TAB, 'a'], '02:30'],
            'correct period' => [[Key::SHIFT_TAB, Key::BACKSPACE, 'm'], '14:30'],
            'replace incomplete period' => [[Key::SHIFT_TAB, Key::BACKSPACE, 'a'], '02:30'],
            'hour wraps within period' => [['12', Key::LEFT, Key::UP], '13:30'],
        ];
    }

    #[DataProvider('invalidTypedHours')]
    public function testRejectsInvalidTypedHoursBeforeAdvancing(bool $use12Hours, string $input, string $correction): void
    {
        Prompt::fake([$input, Key::TAB, Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, $correction, Key::ENTER]);

        $result = timepicker('Start time', default: '14:30', use12Hours: $use12Hours);

        $this->assertSame('14:30', $result->format('H:i'));
        Prompt::assertOutputContains('Invalid time.');
    }

    public static function invalidTypedHours(): array
    {
        return [[false, '24', '14'], [true, '00', '02'], [true, '13', '02']];
    }

    public function testStopsMalformedPastedInputAtTheInvalidSegment(): void
    {
        Prompt::fake(['24:01', Key::ENTER, Key::CTRL_C]);

        $prompt = new TimePickerPrompt('Start time', default: '14:30');
        $prompt->prompt();

        $this->assertSame('cancel', $prompt->state);
        $this->assertSame('24', $prompt->segmentBuffer);
    }

    public function testValidatesClockTimeBoundsIndependentlyOfTheSuppliedDates(): void
    {
        Prompt::fake(['10:00', Key::ENTER]);

        $result = timepicker('Start time', default: '2026-07-24 09:30', min: '2020-01-01 09:30', max: '2030-01-01 10:15');

        $this->assertSame('2026-07-24 10:00', $result->format('Y-m-d H:i'));
    }

    public function testRejectsTypedTimesOutsideTheRange(): void
    {
        Prompt::fake(['08:00', Key::ENTER, Key::LEFT, '09', Key::ENTER]);

        $result = timepicker('Start time', default: '09:30', min: '09:00');

        $this->assertSame('09:00', $result->format('H:i'));
        Prompt::assertOutputContains('Must be on or after 09:00.');
    }

    public function testPreservesTheDefaultDateAndTimezone(): void
    {
        Prompt::fake(['09:45', Key::ENTER]);

        $result = timepicker('Start time', default: new DateTimeImmutable('2026-07-24 14:30', new DateTimeZone('America/New_York')));

        $this->assertSame('2026-07-24 09:45 America/New_York', $result->format('Y-m-d H:i e'));
    }

    public function testValidatesAndTransformsACommittedTime(): void
    {
        Prompt::fake(['09:45', Key::ENTER]);

        $validated = null;
        $result = timepicker('Start time', default: '14:30', transform: fn (DateTimeImmutable $value) => $value->format('H:i'), validate: function (string $value) use (&$validated): ?string {
            $validated = $value;

            return null;
        });

        $this->assertSame('09:45', $result);
        $this->assertSame($result, $validated);
    }

    public function testReturnsTheDefaultOrNullWhenNonInteractive(): void
    {
        Prompt::interactive(false);

        $this->assertSame('14:30', timepicker('Start time', default: '14:30')->format('H:i'));
        $this->assertNull(timepicker('Start time'));
    }

    public function testRejectsRequiredMissingNonInteractiveTimes(): void
    {
        Prompt::interactive(false);

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Required.');

        timepicker('Start time', required: true);
    }

    public function testRejectsReversedClockTimeBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('min');

        timepicker('Start time', min: '22:00', max: '06:00');
    }

    #[DataProvider('invalidTimeDefaults')]
    public function testRejectsInvalidTimeDefaults(string $default): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Time');

        timepicker('Start time', default: $default);
    }

    public static function invalidTimeDefaults(): array
    {
        return [['not-a-time'], ['25:00'], ['24:00']];
    }

    public function testRejectsNonInteractiveDefaultsOutsideTheClockTimeRange(): void
    {
        Prompt::interactive(false);

        $this->expectException(NonInteractiveValidationException::class);
        $this->expectExceptionMessage('Must be on or after 09:00.');

        timepicker('Start time', default: '08:00', min: '09:00');
    }

    public function testClampsSteppingAndDefaultsToClockTimeBounds(): void
    {
        Prompt::fake([Key::UP, Key::ENTER]);

        $result = timepicker('Start time', default: '2026-07-24 11:00', max: '10:15');

        $this->assertSame('2026-07-24 10:15', $result->format('Y-m-d H:i'));
    }

    public function testPreservesHintsAndAllowsAnEmptyHint(): void
    {
        Prompt::fake([Key::ENTER]);
        timepicker('Start time', default: '14:30', hint: 'Choose a time.');
        Prompt::assertStrippedOutputContains('Choose a time.');

        Prompt::fake([Key::ENTER]);
        timepicker('Start time', default: '14:30', hint: '');
        Prompt::assertStrippedOutputDoesntContain('Left/Right or Tab');
    }

    public function testDoesNotSubmitAnIncompleteTimeSegment(): void
    {
        Prompt::fake([Key::BACKSPACE, Key::BACKSPACE, Key::ENTER, '09', Key::ENTER]);

        $result = timepicker('Start time', default: '14:30');

        $this->assertSame('09:30', $result->format('H:i'));
        Prompt::assertOutputContains('Incomplete hour.');
    }

    #[DataProvider('minuteAndSecondFocusKeys')]
    public function testRejectsInvalidMinuteAndSecondSegments(array $keys): void
    {
        Prompt::fake([...$keys, '60', Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, '59', Key::ENTER]);

        timepicker('Start time', default: '14:30:10', withSeconds: true);

        Prompt::assertOutputContains('Invalid time.');
    }

    public static function minuteAndSecondFocusKeys(): array
    {
        return [
            'minute' => [[Key::TAB]],
            'second' => [[Key::SHIFT_TAB]],
        ];
    }

    public function testIgnoresEscapeSequencesWhileEditingATime(): void
    {
        Prompt::fake([Key::DELETE, "\e[1;5C", Key::ENTER]);

        $this->assertSame('14:30', timepicker('Start time', default: '14:30')->format('H:i'));
    }

    public function testCanCancelATimepicker(): void
    {
        Prompt::fake([Key::CTRL_C]);

        timepicker('Start time', default: '14:30');

        Prompt::assertOutputContains('Cancelled.');
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);
        TimePickerPrompt::fallbackUsing(fn (TimePickerPrompt $prompt) => new DateTimeImmutable('09:45'));

        $this->assertSame('09:45', timepicker('Start time')->format('H:i'));
    }

    public function testExposesTheOriginalValidationRulesToCustomValidators(): void
    {
        Prompt::validateUsing(function (Prompt $prompt): ?string {
            $this->assertSame('business-hours', $prompt->validate);

            return $prompt->value()->format('H') < 9 ? 'Choose business hours.' : null;
        });
        Prompt::fake([Key::ENTER, '09', Key::ENTER]);

        $this->assertSame('09:00', timepicker('Start time', default: '08:00', validate: 'business-hours')->format('H:i'));
        Prompt::assertOutputContains('Choose business hours.');
    }

    public function testRendersAndEdits12HourSeconds(): void
    {
        Prompt::fake(['12:05:45 AM', Key::ENTER]);

        $result = timepicker('Start time', default: '14:30:10', withSeconds: true, use12Hours: true);

        $this->assertSame('00:05:45', $result->format('H:i:s'));
        Prompt::assertStrippedOutputContains('12:05:45 AM');
    }

    public function testValidates12HourBoundsUsing24HourClockValues(): void
    {
        Prompt::fake(['09:00 AM', Key::ENTER, 'p', Key::ENTER]);

        $result = timepicker('Start time', default: '14:30', min: '12:00', max: '22:00', use12Hours: true);

        $this->assertSame('21:00', $result->format('H:i'));
        Prompt::assertOutputContains('Must be on or after 12:00 PM.');
    }

    public function testStopsInputAfterTheFinalTimeSegment(): void
    {
        Prompt::fake(['09:45:59', Key::ENTER]);

        $this->assertSame('09:45:00', timepicker('Start time', default: '14:30')->format('H:i:s'));
    }

    #[DataProvider('pastedPeriods')]
    public function testPreservesThePeriodWhenPastingSecondsAtEitherPrecision(string $period, bool $withSeconds, string $expected): void
    {
        Prompt::fake(["09:45:12 {$period}", Key::ENTER]);

        $result = timepicker('Start time', default: $period === 'PM' ? '02:30' : '14:30', use12Hours: true, withSeconds: $withSeconds);

        $this->assertSame($expected, $result->format('H:i:s'));
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

    #[DataProvider('daylightSavingGapInputs')]
    public function testRejectsTypedTimesInADaylightSavingGap(bool $use12Hours, string $default, array $keys, string $focused, string $buffer): void
    {
        Prompt::fake([...$keys, Key::ENTER, Key::CTRL_C]);

        $prompt = new TimePickerPrompt('Start time', default: new DateTimeImmutable($default, new DateTimeZone('America/New_York')), use12Hours: $use12Hours);
        $prompt->prompt();

        $this->assertSame('cancel', $prompt->state);
        $this->assertSame($focused, $prompt->focused);
        $this->assertSame($buffer, $prompt->segmentBuffer);
        $this->assertNotSame('03:15', $prompt->date->format('H:i'));
        Prompt::assertOutputContains('Invalid time.');
    }

    public static function daylightSavingGapInputs(): array
    {
        return [
            '24h hour' => [false, '2026-03-08 01:30', ['02:15'], 'hour', '02'],
            '12h period' => [true, '2026-03-08 14:15', ['02:15 AM'], 'period', 'AM'],
        ];
    }

    #[DataProvider('daylightSavingGapSteps')]
    public function testDoesNotStepIntoADaylightSavingGap(bool $use12Hours, string $default, array $keys, string $expected): void
    {
        Prompt::fake([...$keys, Key::ENTER]);

        $result = timepicker('Start time', default: new DateTimeImmutable($default, new DateTimeZone('America/New_York')), use12Hours: $use12Hours);

        $this->assertSame($expected, $result->format('H:i'));
        Prompt::assertOutputContains('Invalid time.');
    }

    public static function daylightSavingGapSteps(): array
    {
        return [
            '24h hour' => [false, '2026-03-08 01:30', [Key::UP], '01:30'],
            '12h hour' => [true, '2026-03-08 01:30', [Key::UP], '01:30'],
            '12h period' => [true, '2026-03-08 14:15', [Key::SHIFT_TAB, Key::UP], '14:15'],
        ];
    }

    public function testRejectsAnUnrepresentableDaylightSavingClampTarget(): void
    {
        Prompt::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be represented');

        timepicker('Start time', default: new DateTimeImmutable('2026-03-08 04:30', new DateTimeZone('America/New_York')), max: '02:30');
    }

    public function testPreservesThePreviousTimeWhenSteppingWouldClampToADaylightSavingGap(): void
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = timepicker('Start time', default: new DateTimeImmutable('2026-03-08 00:30', new DateTimeZone('America/New_York')), max: '02:30');

        $this->assertSame('00:30', $result->format('H:i'));
        Prompt::assertOutputContains('cannot be represented');
    }

    #[DataProvider('fullSegmentInputs')]
    public function testAdvancesToTheNextSegmentOnceTheTypedSegmentIsFull(bool $use12Hours, array $keys, string $expected): void
    {
        Prompt::fake([...$keys, Key::ENTER]);

        $result = timepicker(label: 'Start', default: '09:00', withSeconds: true, use12Hours: $use12Hours);

        $this->assertSame($expected, $result->format('H:i:s'));
    }

    public static function fullSegmentInputs(): array
    {
        return [
            '24 hour digits only' => [false, ['143005'], '14:30:05'],
            '12 hour digits and period' => [true, ['023005p'], '14:30:05'],
        ];
    }
}
