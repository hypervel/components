<?php

declare(strict_types=1);

use Hypervel\Console\OutputStyle;
use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Output\ConsoleOutput;
use Hypervel\Prompts\Terminal;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\StreamOutput;

require __DIR__ . '/../../../vendor/autoload.php';

$terminal = new Terminal;
$memory = static fn (): StreamOutput => new StreamOutput(fopen('php://memory', 'w'));

echo json_encode([
    'console' => $terminal->isOutputTty(new ConsoleOutput),
    'artisan' => $terminal->isOutputTty(new OutputStyle(new ArrayInput([]), new ConsoleOutput)),
    'memory' => $terminal->isOutputTty($memory()),
    'captured artisan' => $terminal->isOutputTty(new OutputStyle(new ArrayInput([]), $memory())),
    'buffered' => $terminal->isOutputTty(new BufferedOutput),
    'prompts buffer' => $terminal->isOutputTty(new BufferedConsoleOutput),
], JSON_THROW_ON_ERROR);
