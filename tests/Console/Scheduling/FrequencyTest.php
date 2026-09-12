<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Scheduling\Event;
use Hypervel\Tests\Console\Fixtures\FakeEventMutex;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class FrequencyTest extends TestCase
{
    protected Event $event;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->event = new Event(new FakeEventMutex, 'php foo');
    }

    public function testEveryMinute(): void
    {
        $this->assertSame('* * * * *', $this->event->getExpression());
        $this->assertSame('* * * * *', $this->event->everyMinute()->getExpression());
    }

    public function testEveryXMinutes(): void
    {
        $this->assertSame('*/2 * * * *', $this->event->everyTwoMinutes()->getExpression());
        $this->assertSame('*/3 * * * *', $this->event->everyThreeMinutes()->getExpression());
        $this->assertSame('*/4 * * * *', $this->event->everyFourMinutes()->getExpression());
        $this->assertSame('*/5 * * * *', $this->event->everyFiveMinutes()->getExpression());
        $this->assertSame('*/10 * * * *', $this->event->everyTenMinutes()->getExpression());
        $this->assertSame('*/15 * * * *', $this->event->everyFifteenMinutes()->getExpression());
        $this->assertSame('*/30 * * * *', $this->event->everyThirtyMinutes()->getExpression());
    }

    public function testDaily(): void
    {
        $this->assertSame('0 0 * * *', $this->event->daily()->getExpression());
    }

    public function testDailyAt(): void
    {
        $this->assertSame('8 13 * * *', $this->event->dailyAt('13:08')->getExpression());
    }

    public function testDailyAtParsesMinutesAndIgnoresSecondsWhenSecondsAreDefined(): void
    {
        $this->assertSame('8 13 * * *', $this->event->dailyAt('13:08:10')->getExpression());
    }

    public function testTwiceDaily(): void
    {
        $this->assertSame('0 3,15 * * *', $this->event->twiceDaily(3, 15)->getExpression());
    }

    public function testTwiceDailyAt(): void
    {
        $this->assertSame('5 3,15 * * *', $this->event->twiceDailyAt(3, 15, 5)->getExpression());
    }

    public function testWeekly(): void
    {
        $this->assertSame('0 0 * * 0', $this->event->weekly()->getExpression());
    }

    public function testWeeklyOn(): void
    {
        $this->assertSame('0 8 * * 1', $this->event->weeklyOn(1, '8:00')->getExpression());
    }

    public function testOverrideWithHourly(): void
    {
        $this->assertSame('0 * * * *', $this->event->everyFiveMinutes()->hourly()->getExpression());
        $this->assertSame('37 * * * *', $this->event->hourlyAt(37)->getExpression());
        $this->assertSame('*/10 * * * *', $this->event->hourlyAt('*/10')->getExpression());
        $this->assertSame('15,30,45 * * * *', $this->event->hourlyAt([15, 30, 45])->getExpression());
    }

    public function testHourly(): void
    {
        $this->assertSame('0 1-23/2 * * *', $this->event->everyOddHour()->getExpression());
        $this->assertSame('0 */2 * * *', $this->event->everyTwoHours()->getExpression());
        $this->assertSame('0 */3 * * *', $this->event->everyThreeHours()->getExpression());
        $this->assertSame('0 */4 * * *', $this->event->everyFourHours()->getExpression());
        $this->assertSame('0 */6 * * *', $this->event->everySixHours()->getExpression());

        $this->assertSame('37 1-23/2 * * *', $this->event->everyOddHour(37)->getExpression());
        $this->assertSame('37 */2 * * *', $this->event->everyTwoHours(37)->getExpression());
        $this->assertSame('37 */3 * * *', $this->event->everyThreeHours(37)->getExpression());
        $this->assertSame('37 */4 * * *', $this->event->everyFourHours(37)->getExpression());
        $this->assertSame('37 */6 * * *', $this->event->everySixHours(37)->getExpression());

        $this->assertSame('*/10 1-23/2 * * *', $this->event->everyOddHour('*/10')->getExpression());
        $this->assertSame('*/10 */2 * * *', $this->event->everyTwoHours('*/10')->getExpression());
        $this->assertSame('*/10 */3 * * *', $this->event->everyThreeHours('*/10')->getExpression());
        $this->assertSame('*/10 */4 * * *', $this->event->everyFourHours('*/10')->getExpression());
        $this->assertSame('*/10 */6 * * *', $this->event->everySixHours('*/10')->getExpression());

        $this->assertSame('15,30,45 1-23/2 * * *', $this->event->everyOddHour([15, 30, 45])->getExpression());
        $this->assertSame('15,30,45 */2 * * *', $this->event->everyTwoHours([15, 30, 45])->getExpression());
        $this->assertSame('15,30,45 */3 * * *', $this->event->everyThreeHours([15, 30, 45])->getExpression());
        $this->assertSame('15,30,45 */4 * * *', $this->event->everyFourHours([15, 30, 45])->getExpression());
        $this->assertSame('15,30,45 */6 * * *', $this->event->everySixHours([15, 30, 45])->getExpression());
    }

    public function testMonthly(): void
    {
        $this->assertSame('0 0 1 * *', $this->event->monthly()->getExpression());
    }

    public function testMonthlyOn(): void
    {
        $this->assertSame('0 15 4 * *', $this->event->monthlyOn(4, '15:00')->getExpression());
    }

    public function testLastDayOfMonth(): void
    {
        $this->assertSame('0 0 L * *', $this->event->lastDayOfMonth()->getExpression());
    }

    public function testRepeatEveryRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The seconds [0] must be greater than zero.');

        (fn () => $this->repeatEvery(0))->call($this->event);
    }

    public function testRepeatEveryRejectsNegativeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The seconds [-5] must be greater than zero.');

        (fn () => $this->repeatEvery(-5))->call($this->event);
    }

    public function testTwiceMonthly(): void
    {
        $this->assertSame('0 0 1,16 * *', $this->event->twiceMonthly(1, 16)->getExpression());
    }

    public function testTwiceMonthlyAtTime(): void
    {
        $this->assertSame('30 1 1,16 * *', $this->event->twiceMonthly(1, 16, '1:30')->getExpression());
    }

    public function testMonthlyOnWithMinutes(): void
    {
        $this->assertSame('15 15 4 * *', $this->event->monthlyOn(4, '15:15')->getExpression());
    }

    public function testWeekdaysDaily(): void
    {
        $this->assertSame('0 0 * * 1-5', $this->event->weekdays()->daily()->getExpression());
    }

    public function testWeekdaysHourly(): void
    {
        $this->assertSame('0 * * * 1-5', $this->event->weekdays()->hourly()->getExpression());
    }

    public function testWeekdays(): void
    {
        $this->assertSame('* * * * 1-5', $this->event->weekdays()->getExpression());
    }

    public function testWeekends(): void
    {
        $this->assertSame('* * * * 6,0', $this->event->weekends()->getExpression());
    }

    public function testSundays(): void
    {
        $this->assertSame('* * * * 0', $this->event->sundays()->getExpression());
    }

    public function testMondays(): void
    {
        $this->assertSame('* * * * 1', $this->event->mondays()->getExpression());
    }

    public function testTuesdays(): void
    {
        $this->assertSame('* * * * 2', $this->event->tuesdays()->getExpression());
    }

    public function testWednesdays(): void
    {
        $this->assertSame('* * * * 3', $this->event->wednesdays()->getExpression());
    }

    public function testThursdays(): void
    {
        $this->assertSame('* * * * 4', $this->event->thursdays()->getExpression());
    }

    public function testFridays(): void
    {
        $this->assertSame('* * * * 5', $this->event->fridays()->getExpression());
    }

    public function testSaturdays(): void
    {
        $this->assertSame('* * * * 6', $this->event->saturdays()->getExpression());
    }

    public function testQuarterly(): void
    {
        $this->assertSame('0 0 1 1-12/3 *', $this->event->quarterly()->getExpression());
    }

    public function testQuarterlyOn(): void
    {
        $this->assertSame('0 15 4 1-12/3 *', $this->event->quarterlyOn(4, '15:00')->getExpression());
    }

    public function testYearly(): void
    {
        $this->assertSame('0 0 1 1 *', $this->event->yearly()->getExpression());
    }

    public function testYearlyOn(): void
    {
        $this->assertSame('8 15 5 4 *', $this->event->yearlyOn(4, 5, '15:08')->getExpression());
    }

    public function testYearlyOnMondaysOnly(): void
    {
        $this->assertSame('1 9 * 7 1', $this->event->mondays()->yearlyOn(7, '*', '09:01')->getExpression());
    }

    public function testYearlyOnTuesdaysAndDayOfMonth20(): void
    {
        $this->assertSame('1 9 20 7 2', $this->event->tuesdays()->yearlyOn(7, 20, '09:01')->getExpression());
    }

    public function testFrequencyMacro(): void
    {
        Event::macro('everyXMinutes', function (int $x): Event {
            return $this->spliceIntoPosition(1, "*/{$x}");
        });

        $this->assertSame('*/6 * * * *', $this->event->everyXMinutes(6)->getExpression());
    }
}
