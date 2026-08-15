#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require __DIR__ . '/../vendor/autoload.php';

/*
 * Report every test whose full lifecycle takes at least this many seconds.
 * Lower the threshold when investigating smaller contributors to suite runtime.
 */
$slowTestThresholdSeconds = 0.3;

$reportPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hypervel-components-profile-'
    . getmypid() . '-' . bin2hex(random_bytes(6)) . '.xml';

$process = new Process([
    PHP_BINARY,
    __DIR__ . '/../vendor/bin/paratest',
    '--log-junit=' . $reportPath,
    ...array_slice($argv, 1),
], cwd: dirname(__DIR__));
$process->setTimeout(null);

if (stream_isatty(STDOUT)) {
    try {
        $process->setTty(true);
    } catch (RuntimeException) {
    }
}

try {
    $exitCode = $process->run(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });

    if (! is_file($reportPath)) {
        if ($exitCode === 0) {
            throw new RuntimeException(sprintf('Unable to find PHPUnit profile [%s].', $reportPath));
        }
    } else {
        $tests = [];

        $document = new DOMDocument;

        if (! @$document->load($reportPath)) {
            throw new RuntimeException(sprintf('Unable to read PHPUnit profile [%s].', $reportPath));
        }

        // PHPUnit's JUnit timer starts at PreparationStarted, matching ProfileTracker.
        foreach ($document->getElementsByTagName('testcase') as $test) {
            if (! $test instanceof DOMElement || ! $test->hasAttribute('time')) {
                continue;
            }

            $name = $test->getAttribute('name');
            $class = $test->getAttribute('class');

            $duration = (float) $test->getAttribute('time');

            if ($duration < $slowTestThresholdSeconds) {
                continue;
            }

            $tests[] = [
                'name' => $class === '' ? $name : $class . '::' . $name,
                'duration' => $duration,
            ];
        }

        usort(
            $tests,
            static function (array $first, array $second): int {
                $durationComparison = $second['duration'] <=> $first['duration'];

                return $durationComparison !== 0
                    ? $durationComparison
                    : $first['name'] <=> $second['name'];
            },
        );

        if ($tests === []) {
            fwrite(STDOUT, sprintf(
                '%sNo tests took at least %.3f seconds.%s',
                PHP_EOL,
                $slowTestThresholdSeconds,
                PHP_EOL,
            ));
        } else {
            fwrite(STDOUT, sprintf(
                '%sTests taking at least %.3f seconds (%d)%s%s',
                PHP_EOL,
                $slowTestThresholdSeconds,
                count($tests),
                PHP_EOL,
                PHP_EOL,
            ));

            foreach ($tests as $test) {
                fwrite(STDOUT, sprintf('  %.3fs  %s%s', $test['duration'], $test['name'], PHP_EOL));
            }
        }
    }
} finally {
    if (is_file($reportPath)) {
        unlink($reportPath);
    }
}

exit($exitCode);
