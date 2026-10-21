<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Saloon\Repositories\ArrayRepository;
use Hypervel\Tests\TestCase;

class ArrayStoreTest extends TestCase
{
    public function testTheStoreIsEmptyByDefault(): void
    {
        $store = new ArrayRepository;

        $this->assertSame([], $store->all());
    }

    public function testYouCanSetIt(): void
    {
        $store = new ArrayRepository(['name' => 'Gareth', 'superhero' => 'Iron Man']);

        $store->set(['name' => 'Sam']);

        $this->assertSame(['name' => 'Sam'], $store->all());
    }

    public function testYouCanAddAnItem(): void
    {
        $store = new ArrayRepository;
        $store->add('name', 'Sam');
        $store->add('superhero', fn (): string => 'Iron Man');

        $this->assertSame(['name' => 'Sam', 'superhero' => 'Iron Man'], $store->all());
    }

    public function testYouCanConditionallyAddItemsToTheArrayStore(): void
    {
        $store = new ArrayRepository;

        $store->when(true, fn (ArrayRepository $store): ArrayRepository => $store->add('name', 'Gareth'));
        $store->when(false, fn (ArrayRepository $store): ArrayRepository => $store->add('name', 'Sam'));
        $store->when(true, fn (ArrayRepository $store): ArrayRepository => $store->add('sidekick', 'Mantas'));
        $store->when(false, fn (ArrayRepository $store): ArrayRepository => $store->add('sidekick', 'Teo'));

        $this->assertSame(['name' => 'Gareth', 'sidekick' => 'Mantas'], $store->all());
    }

    public function testYouCanDeleteAnItem(): void
    {
        $store = new ArrayRepository(['name' => 'Sam']);
        $store->remove('name');

        $this->assertSame([], $store->all());
    }

    public function testYouCanGetAnItem(): void
    {
        $store = new ArrayRepository(['name' => 'Sam']);

        $this->assertSame('Sam', $store->get('name'));
        $this->assertSame('fallback', $store->get('missing', 'fallback'));
    }

    public function testYouCanGetAllItems(): void
    {
        $store = new ArrayRepository(['name' => 'Sam', 'superhero' => 'Iron Man']);

        $this->assertSame(['name' => 'Sam', 'superhero' => 'Iron Man'], $store->all());
    }

    public function testYouCanMergeItemsTogetherIntoTheContentStore(): void
    {
        $store = new ArrayRepository(['name' => 'Sam', 'superhero' => 'Iron Man']);

        $store->merge(['sidekick' => 'Gareth'], ['superhero' => 'Black Widow']);

        $this->assertSame(['name' => 'Sam', 'superhero' => 'Black Widow', 'sidekick' => 'Gareth'], $store->all());
    }

    public function testYouCanCheckIfTheStoreIsEmptyOrNot(): void
    {
        $store = new ArrayRepository;

        $this->assertTrue($store->isEmpty());
        $this->assertFalse($store->isNotEmpty());

        $store->add('name', 'Sam');

        $this->assertFalse($store->isEmpty());
        $this->assertTrue($store->isNotEmpty());
    }
}
