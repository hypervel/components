<?php

declare(strict_types=1);

use Hypervel\Tests\Support\AnotherExampleParameter;
use Hypervel\Tests\Support\ExampleParameter;

return function (ExampleParameter|AnotherExampleParameter $a, $b) {
};
