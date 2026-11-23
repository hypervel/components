<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

/**
 * The export manager's default driver.
 */
class CsvDriver
{
    /**
     * Export the rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function export(array $rows): string
    {
        return '';
    }

    /**
     * Include a header row.
     */
    public function withHeader(): static
    {
        return $this;
    }

    /**
     * Export into the given buffer.
     */
    public function exportInto(string &$buffer): void
    {
        $buffer .= '';
    }

    /**
     * Count the exported rows, which the manager documents differently.
     */
    public function count(): string
    {
        return '';
    }

    /**
     * Create a driver.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Format a row.
     */
    protected function format(): string
    {
        return '';
    }
}
