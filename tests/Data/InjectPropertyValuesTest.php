<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\InjectPropertyValuesTest;

use Attribute;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class InjectPropertyValuesTest extends TestCase
{
    // REMOVED: Spatie's InjectsPropertyValue contract; a custom Hypervel contextual attribute supplies a constructor value instead.
    // REMOVED: 'can fill data properties from injected parameter properties' and 'can fill data properties from injected parameters using custom property mapping'; property paths are covered through RouteParameter and CurrentUser in the From* attribute tests.
    // REMOVED: 'skips replacing properties when route parameter properties exist and replacing is disabled' and 'skips properties it cannot find a route parameter for'; a contextual value always wins, including null.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        FromTestPayload::$payload = [];

        parent::tearDown();
    }

    public function testCanFillDataPropertiesWithInjectedParameters(): void
    {
        FromTestPayload::$payload = [
            'string' => 'Hello World',
            'array' => ['a', 'b'],
            'data' => new SimpleData('Hello World'),
        ];

        $dataClass = new class('', [], new SimpleData('')) extends Data {
            /**
             * Create the data object from injected values.
             */
            public function __construct(
                #[FromTestPayload('string')]
                public string $string,
                #[FromTestPayload('array')]
                public array $array,
                #[FromTestPayload('data')]
                public SimpleData $data,
            ) {
            }
        };

        $data = $dataClass::from();

        $this->assertSame('Hello World', $data->string);
        $this->assertSame(['a', 'b'], $data->array);
        $this->assertInstanceOf(SimpleData::class, $data->data);
        $this->assertSame('Hello World', $data->data->string);
    }

    public function testReplacesPropertiesWhenInjectedParameterPropertiesExist(): void
    {
        FromTestPayload::$payload = [
            'foo' => 'Rick',
            'user_id' => 2,
        ];

        $dataClass = new class('', 0) extends Data {
            /**
             * Create the data object from injected values.
             */
            public function __construct(
                #[FromTestPayload('foo')]
                public string $name,
                #[FromTestPayload('user_id'), MapInputName('user_id')]
                public int $userId,
            ) {
            }
        };

        $data = $dataClass::from([
            'name' => 'Jon',
            'user_id' => 1,
        ]);

        $this->assertSame('Rick', $data->name);
        $this->assertSame(2, $data->userId);
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
class FromTestPayload implements ContextualAttribute
{
    public static array $payload = [];

    /**
     * Create an attribute reading one value from the test payload.
     */
    public function __construct(
        public string $parameter,
    ) {
    }

    /**
     * Resolve the value from the test payload.
     */
    public static function resolve(self $attribute, Container $container): mixed
    {
        return static::$payload[$attribute->parameter] ?? null;
    }
}
