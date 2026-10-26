<?php

declare(strict_types=1);

use Hypervel\Foundation\Bootstrap\ConfigureTerminalDimensions;
use Symfony\Component\Console\Terminal;

require __DIR__ . '/../../../vendor/autoload.php';

ConfigureTerminalDimensions::configure();

echo json_encode([
    stream_isatty(STDOUT),
    getenv('COLUMNS'),
    getenv('LINES'),
    (new ReflectionProperty(Terminal::class, 'width'))->getValue(),
    (new ReflectionProperty(Terminal::class, 'height'))->getValue(),
], JSON_THROW_ON_ERROR);
