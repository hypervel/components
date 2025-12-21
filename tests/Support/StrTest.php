<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use BackedEnum;
use Hypervel\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * @internal
 * @coversNothing
 */
class StrTest extends TestCase
{
    public function testFromWithString(): void
    {
        $this->assertSame('hello', Str::from('hello'));
        $this->assertSame('', Str::from(''));
        $this->assertSame('with spaces', Str::from('with spaces'));
    }

    public function testFromWithInt(): void
    {
        $result = Str::from(42);

        $this->assertIsString($result);
        $this->assertSame('42', $result);
        $this->assertSame('0', Str::from(0));
        $this->assertSame('-1', Str::from(-1));
    }

    public function testFromWithStringBackedEnum(): void
    {
        $this->assertSame('active', Str::from(TestStringStatus::Active));
        $this->assertSame('pending', Str::from(TestStringStatus::Pending));
        $this->assertSame('archived', Str::from(TestStringStatus::Archived));
    }

    public function testFromWithIntBackedEnum(): void
    {
        $result = Str::from(TestIntStatus::Ok);

        $this->assertIsString($result);
        $this->assertSame('200', $result);
        $this->assertSame('404', Str::from(TestIntStatus::NotFound));
        $this->assertSame('500', Str::from(TestIntStatus::ServerError));
    }

    public function testFromWithStringable(): void
    {
        $this->assertSame('stringable-value', Str::from(new TestStringable('stringable-value')));
        $this->assertSame('', Str::from(new TestStringable('')));
        $this->assertSame('with spaces', Str::from(new TestStringable('with spaces')));
    }

    public function testFromAllWithStrings(): void
    {
        $result = Str::fromAll(['users', 'posts', 'comments']);

        $this->assertSame(['users', 'posts', 'comments'], $result);
    }

    public function testFromAllWithEnums(): void
    {
        $result = Str::fromAll([
            TestStringStatus::Active,
            TestStringStatus::Pending,
            TestStringStatus::Archived,
        ]);

        $this->assertSame(['active', 'pending', 'archived'], $result);
    }

    public function testFromAllWithIntBackedEnums(): void
    {
        $result = Str::fromAll([
            TestIntStatus::Ok,
            TestIntStatus::NotFound,
        ]);

        $this->assertSame(['200', '404'], $result);
    }

    public function testFromAllWithStringables(): void
    {
        $result = Str::fromAll([
            new TestStringable('first'),
            new TestStringable('second'),
        ]);

        $this->assertSame(['first', 'second'], $result);
    }

    public function testFromAllWithMixedInput(): void
    {
        $result = Str::fromAll([
            'users',
            TestStringStatus::Active,
            42,
            TestIntStatus::NotFound,
            new TestStringable('dynamic-tag'),
            'legacy-tag',
        ]);

        $this->assertSame(['users', 'active', '42', '404', 'dynamic-tag', 'legacy-tag'], $result);
    }

    public function testFromAllWithEmptyArray(): void
    {
        $this->assertSame([], Str::fromAll([]));
    }

    public function testFromAllPreservesArrayKeys(): void
    {
        $result = Str::fromAll([
            'first' => TestStringStatus::Active,
            'second' => 'manual',
            0 => TestIntStatus::Ok,
        ]);

        $this->assertSame([
            'first' => 'active',
            'second' => 'manual',
            0 => '200',
        ], $result);
    }

    #[DataProvider('fromDataProvider')]
    public function testFromWithDataProvider(string|int|BackedEnum|Stringable $input, string $expected): void
    {
        $this->assertSame($expected, Str::from($input));
    }

    public static function fromDataProvider(): iterable
    {
        yield 'string value' => ['hello', 'hello'];
        yield 'empty string' => ['', ''];
        yield 'integer' => [123, '123'];
        yield 'zero' => [0, '0'];
        yield 'negative integer' => [-42, '-42'];
        yield 'string-backed enum' => [TestStringStatus::Active, 'active'];
        yield 'int-backed enum' => [TestIntStatus::Ok, '200'];
        yield 'stringable' => [new TestStringable('from-stringable'), 'from-stringable'];
    }
}

enum TestStringStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Archived = 'archived';
}

enum TestIntStatus: int
{
    case Ok = 200;
    case NotFound = 404;
    case ServerError = 500;
}

class TestStringable implements Stringable
{
    public function __construct(
        private readonly string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
