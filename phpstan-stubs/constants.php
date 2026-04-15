<?php

declare(strict_types=1);

/**
 * Constants defined at runtime that phpstan would otherwise not know about.
 *
 * BASE_PATH is defined in src/testbench/src/Bootstrapper.php when the test
 * suite bootstraps. Other callsites guard with defined('BASE_PATH'), but a
 * few usages reference it directly inside methods that only run after the
 * bootstrap has defined it.
 */
const BASE_PATH = '';
