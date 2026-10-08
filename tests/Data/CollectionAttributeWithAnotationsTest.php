<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\CollectionAttributeWithAnotationsTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Collections\SimpleDataCollectionWithAnotations;
use Hypervel\Tests\Data\Fixtures\DataValidationAsserter;
use Hypervel\Tests\Data\Fixtures\DataWithSimpleDataCollectionWithAnotations;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class CollectionAttributeWithAnotationsTest extends TestCase
{
    protected array $payload = [
        'collection' => [
            ['string' => 'string1'],
            ['string' => 'string2'],
            ['string' => 'string3'],
        ],
    ];

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanCreateADataObjectWithACollectionAttributeFromArrayAndBack(): void
    {
        $data = DataWithSimpleDataCollectionWithAnotations::from($this->payload);

        $this->assertEquals(new DataWithSimpleDataCollectionWithAnotations(
            collection: new SimpleDataCollectionWithAnotations([
                new SimpleData(string: 'string1'),
                new SimpleData(string: 'string2'),
                new SimpleData(string: 'string3'),
            ])
        ), $data);

        $this->assertSame($this->payload, $data->toArray());
    }

    public function testCanValidateADataObjectWithACollectionAttribute(): void
    {
        DataValidationAsserter::for(DataWithSimpleDataCollectionWithAnotations::class)
            ->assertOk($this->payload)
            ->assertErrors(['collection' => [
                ['notExistingAttribute' => 'xxx'],
            ]])
            ->assertRules(
                rules: [
                    'collection' => ['present', 'array'],
                    'collection.0.string' => ['required', 'string'],
                    'collection.1.string' => ['required', 'string'],
                    'collection.2.string' => ['required', 'string'],
                ],
                payload: $this->payload
            );
    }
}
