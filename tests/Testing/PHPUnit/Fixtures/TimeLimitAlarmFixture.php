<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns {
    use Hypervel\Tests\Testing\PHPUnit\Fixtures\TimeLimitAlarmFixture;

    /**
     * Deliver a pending native alarm when the watchdog cancels it.
     */
    function pcntl_alarm(int $seconds): int
    {
        if ($seconds === 0 && TimeLimitAlarmFixture::$deliverAlarm) {
            TimeLimitAlarmFixture::$deliverAlarm = false;
            file_put_contents((string) getenv('TIME_LIMIT_FIXTURE_LOG'), 'alarm delivered' . PHP_EOL, FILE_APPEND);
            posix_kill(getmypid(), SIGALRM);
            pcntl_signal_dispatch();
        }

        return \pcntl_alarm($seconds);
    }
}

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures {
    use Hypervel\Tests\TestCase;
    use Swoole\Coroutine;

    class TimeLimitAlarmFixture extends TestCase
    {
        public static bool $deliverAlarm = false;

        public function testAlarmAtCancellationIsReportedAsTimeout(): void
        {
            // Keep the watchdog's deadline, but deliver the only SIGALRM at cancellation
            // so the regression cannot depend on which real timer fires first.
            \pcntl_alarm(0);
            self::$deliverAlarm = true;

            Coroutine::sleep(30);

            $this->fail('The sleeping test was not interrupted.');
        }
    }
}
