<?php

declare(strict_types=1);

$suite = $argv[1] ?? null;
$outputDirectory = $argv[2] ?? null;

if (! in_array($suite, ['server', 'client'], true) || $outputDirectory === null) {
    fwrite(STDERR, "Usage: php score.php <server|client> <output-directory>\n");

    exit(1);
}

$resultsDir = $outputDirectory . '/results/' . $suite;

if (! is_dir($resultsDir)) {
    fwrite(STDERR, sprintf("No results in [%s]. Run the suite with --output-dir first.\n", $resultsDir));

    exit(1);
}

$directory = new RecursiveDirectoryIterator($resultsDir, FilesystemIterator::SKIP_DOTS);

$passed = 0;
$total = 0;

foreach (new RecursiveIteratorIterator($directory) as $file) {
    if ($file->getFilename() !== 'checks.json') {
        continue;
    }

    $checks = json_decode((string) file_get_contents($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($checks)) {
        throw new UnexpectedValueException('Conformance checks must be a JSON array.');
    }

    foreach ($checks as $check) {
        $status = $check['status'] ?? null;

        if ($status === 'SUCCESS') {
            ++$passed;
            ++$total;
        } elseif ($status === 'FAILURE') {
            ++$total;
        }
    }
}

if ($total === 0) {
    fwrite(STDERR, "No completed conformance checks were found.\n");

    exit(1);
}

$percentage = (int) round($passed / $total * 100);

$color = match (true) {
    $percentage >= 90 => 'brightgreen',
    $percentage >= 70 => 'yellow',
    default => 'orange',
};

if (file_put_contents($outputDirectory . '/' . $suite . '-conformance.json', json_encode([
    'schemaVersion' => 1,
    'label' => $suite . ' conformance',
    'message' => sprintf('%d/%d (%d%%)', $passed, $total, $percentage),
    'color' => $color,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    fwrite(STDERR, "Unable to write the conformance summary.\n");

    exit(1);
}

printf("%s conformance: %d/%d (%d%%)\n", $suite, $passed, $total, $percentage);
