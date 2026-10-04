<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Normalizers\JsonNormalizerTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\MultiData;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonNormalizerTest extends TestCase
{
    // Spatie's JsonNormalizer class is not included; Hypervel decodes JSON strings through its fixed source handling.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanCreateADataObjectFromJson(): void
    {
        $originalData = new MultiData('Hello', 'World');

        $createdData = MultiData::from($originalData->toJson());

        $this->assertEquals($originalData->all(), $createdData->all());
    }

    #[DataProvider('unreadableValues')]
    public function testWontCreateADataObjectFromAValueThatIsNotAJsonObject(mixed $value): void
    {
        $this->expectException(CannotCreateData::class);

        MultiData::from($value);
    }

    /**
     * Get the values upstream's three rejection cases use.
     */
    public static function unreadableValues(): iterable
    {
        yield "won't create a data object from a regular string" => ['Hello World'];
        yield "won't create a data object from an integer" => [1234];
        yield "won't create a data object from a string containing only an integer" => ['1234'];
    }
}
