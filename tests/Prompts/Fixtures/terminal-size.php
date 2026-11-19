<?php

declare(strict_types=1);

use Hypervel\Prompts\Terminal;

require __DIR__ . '/../../../vendor/autoload.php';

$terminal = new Terminal;

echo json_encode([$terminal->cols(), $terminal->lines()], JSON_THROW_ON_ERROR);
