<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Completions\EnumCompletionResponseTest;

use Hypervel\Mcp\Server\Completions\DirectCompletionResponse;
use Hypervel\Mcp\Server\Completions\EnumCompletionResponse;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

enum BackedEnumForTest: string
{
    case One = 'value-one';
    case Two = 'value-two';
    case Three = 'value-three';
}

enum PlainEnumForTest
{
    case Active;
    case Inactive;
    case Pending;
}

class EnumCompletionResponseTest extends TestCase
{
    public function testExtractsBackedEnumValues(): void
    {
        $result = new EnumCompletionResponse(BackedEnumForTest::class);

        $resolved = $result->resolve('');

        $this->assertInstanceOf(DirectCompletionResponse::class, $resolved);
        $this->assertSame(['value-one', 'value-two', 'value-three'], $resolved->values());
    }

    public function testExtractsNonBackedEnumNames(): void
    {
        $result = new EnumCompletionResponse(PlainEnumForTest::class);

        $resolved = $result->resolve('');

        $this->assertSame(['Active', 'Inactive', 'Pending'], $resolved->values());
    }

    public function testFiltersEnumValuesByPrefix(): void
    {
        $result = new EnumCompletionResponse(BackedEnumForTest::class);

        $resolved = $result->resolve('value-t');

        $this->assertSame(['value-two', 'value-three'], $resolved->values());
    }

    public function testThrowsExceptionForInvalidEnumClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not an enum');
        new EnumCompletionResponse('NotAnEnum');
    }
}
