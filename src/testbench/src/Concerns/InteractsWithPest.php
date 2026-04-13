<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Pest\Contracts\HasPrintableTestCaseName;

trait InteractsWithPest
{
    use InteractsWithPHPUnit;
    use InteractsWithTestCase;

    /**
     * Determine if the trait is used within testing using Pest.
     */
    public function isRunningTestCaseUsingPest(): bool
    {
        return $this->isRunningTestCase() && static::isRunningViaPestPrinter($this);
    }

    /**
     * Determine if the object implements Pest test runner.
     */
    protected static function isRunningViaPestPrinter(object|string $object): bool
    {
        /* @phpstan-ignore-next-line */
        return isset(class_implements($object, false)[HasPrintableTestCaseName::class]);
    }
}
