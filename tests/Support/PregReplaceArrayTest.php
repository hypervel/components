<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PregReplaceArrayTest extends TestCase
{
    #[DataProvider('providesPregReplaceArrayData')]
    public function testPregReplaceArray(string $pattern, array $replacements, string $subject, string $expectedOutput): void
    {
        $this->assertSame(
            $expectedOutput,
            preg_replace_array($pattern, $replacements, $subject)
        );
    }

    /**
     * Provide sequential replacement cases.
     */
    public static function providesPregReplaceArrayData(): array
    {
        $pointerArray = ['Taylor', 'Otwell'];

        next($pointerArray);

        return [
            ['/:[a-z_]+/', ['8:30', '9:00'], 'The event will take place between :start and :end', 'The event will take place between 8:30 and 9:00'],
            ['/%s/', ['Taylor'], 'Hi, %s', 'Hi, Taylor'],
            ['/%s/', ['Taylor', 'Otwell'], 'Hi, %s %s', 'Hi, Taylor Otwell'],
            ['/%s/', [], 'Hi, %s %s', 'Hi,  '],
            ['/%s/', ['a', 'b', 'c'], 'Hi', 'Hi'],
            ['//', [], '', ''],
            ['/%s/', ['a'], '', ''],
            // non-sequential numeric keys → should still consume in natural order
            ['/%s/', [2 => 'A', 10 => 'B'], '%s %s', 'A B'],
            // associative keys → order should be insertion order, not keys/pointer
            ['/%s/', ['first' => 'A', 'second' => 'B'], '%s %s', 'A B'],
            // values that are "falsy" but must not be treated as empty by mistake, false->'' , null->''
            ['/%s/', ['0', 0, false, null], '%s|%s|%s|%s', '0|0||'],
            // The internal pointer of this array is not at the beginning
            ['/%s/', $pointerArray, 'Hi, %s %s', 'Hi, Taylor Otwell'],
        ];
    }
}
