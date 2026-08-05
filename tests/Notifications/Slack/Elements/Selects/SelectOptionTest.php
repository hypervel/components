<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Elements\Selects;

use Hypervel\Notifications\Slack\BlockKit\Elements\Selects\SelectOption;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Stringable;

class SelectOptionTest extends TestCase
{
    #[DataProvider('validOptionValues')]
    public function testOptionValuesAreNormalized(Stringable|string|int|float|bool $value, string $expected): void
    {
        $this->assertSame($expected, (new SelectOption('Example', $value))->toArray()['value']);
    }

    public static function validOptionValues(): iterable
    {
        yield 'string' => ['Example Value', 'examplevalue'];
        yield 'integer' => [123, '123'];
        yield 'float' => [4.5, '4.5'];
        yield 'boolean' => [true, '1'];
        yield 'stringable' => [new class implements Stringable {
            public function __toString(): string
            {
                return 'Stringable Value';
            }
        }, 'stringablevalue'];
    }

    #[DataProvider('invalidOptionValues')]
    public function testOptionValuesThatNormalizeToEmptyAreRejected(
        Stringable|string|int|float|bool $value
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The option value must contain at least one supported character.');

        new SelectOption('Example', $value);
    }

    public static function invalidOptionValues(): iterable
    {
        yield 'empty' => [''];
        yield 'punctuation' => ['!@#$%^&*()'];
        yield 'non-latin' => ['你好'];
        yield 'false' => [false];
    }
}
