<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Mappers;

use Hypervel\Data\Mappers\CamelCaseMapper;
use Hypervel\Data\Mappers\KebabCaseMapper;
use Hypervel\Data\Mappers\LowerCaseMapper;
use Hypervel\Data\Mappers\NameMapper;
use Hypervel\Data\Mappers\ProvidedNameMapper;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Mappers\StudlyCaseMapper;
use Hypervel\Data\Mappers\UpperCaseMapper;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class NameMapperTest extends TestCase
{
    /**
     * Provide case mapper examples.
     *
     * @return iterable<string, array{NameMapper, string}>
     */
    public static function caseMapperProvider(): iterable
    {
        yield 'camel' => [new CamelCaseMapper, 'firstName'];
        yield 'kebab' => [new KebabCaseMapper, 'first-name'];
        yield 'lower' => [new LowerCaseMapper, 'first name'];
        yield 'snake' => [new SnakeCaseMapper, 'first_name'];
        yield 'studly' => [new StudlyCaseMapper, 'FirstName'];
        yield 'upper' => [new UpperCaseMapper, 'FIRST NAME'];
    }

    #[DataProvider('caseMapperProvider')]
    public function testCaseMappersTransformStringsAndPreserveIntegerKeys(
        NameMapper $mapper,
        string $expected,
    ): void {
        $this->assertSame($expected, $mapper->map('first name'));
        $this->assertSame(10, $mapper->map(10));
    }

    public function testProvidedNameMapperReturnsItsConfiguredName(): void
    {
        $this->assertSame('wire_name', (new ProvidedNameMapper('wire_name'))->map('property'));
        $this->assertSame(10, (new ProvidedNameMapper(10))->map('property'));
    }
}
