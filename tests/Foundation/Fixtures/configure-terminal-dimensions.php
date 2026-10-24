<?php

declare(strict_types=1);

use Hypervel\Foundation\Bootstrap\ConfigureTerminalDimensions;

require __DIR__ . '/../../../vendor/autoload.php';

ConfigureTerminalDimensions::configure();

echo json_encode([stream_isatty(STDOUT), getenv('COLUMNS'), getenv('LINES')], JSON_THROW_ON_ERROR);
