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
    public function testOptionValuesArePreserved(Stringable|string|int|float|bool $value, string $expected): void
    {
        $this->assertSame($expected, (new SelectOption('Example', $value))->toArray()['value']);
    }

    public static function validOptionValues(): iterable
    {
        yield 'string' => ['Example Value', 'Example Value'];
        yield 'integer' => [123, '123'];
        yield 'float' => [4.5, '4.5'];
        yield 'boolean' => [true, '1'];
        yield 'punctuation' => ['!@#$%^&*()', '!@#$%^&*()'];
        yield 'non-latin' => ['你好', '你好'];
        yield 'stringable' => [new class implements Stringable {
            public function __toString(): string
            {
                return 'Stringable Value';
            }
        }, 'Stringable Value'];
    }

    #[DataProvider('invalidOptionValues')]
    public function testEmptyOptionValuesAreRejected(
        Stringable|string|int|float|bool $value
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The option value must not be empty.');

        new SelectOption('Example', $value);
    }

    public static function invalidOptionValues(): iterable
    {
        yield 'empty' => [''];
        yield 'false' => [false];
    }

    public function testOptionValueCanContainOneHundredAndFiftyMultibyteCharacters(): void
    {
        $value = str_repeat('你', 150);

        $this->assertSame($value, (new SelectOption('Example', $value))->toArray()['value']);
    }

    public function testOptionValueCannotExceedOneHundredAndFiftyCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum length for the option value field is 150 characters.');

        new SelectOption('Example', str_repeat('你', 151));
    }
}
