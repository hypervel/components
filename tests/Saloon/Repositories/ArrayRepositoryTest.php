<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Repositories;

use Hypervel\Saloon\Repositories\ArrayRepository;
use Hypervel\Tests\TestCase;

class ArrayRepositoryTest extends TestCase
{
    public function testItStoresMergesAndRemovesValues(): void
    {
        $repository = new ArrayRepository([
            'name' => 'Sam',
            'hero' => 'Iron Man',
        ]);

        $repository->merge(['sidekick' => 'Gareth'], ['hero' => 'Black Widow']);
        $repository->add('lazy', fn () => 'resolved');
        $repository->remove('name');

        $this->assertSame([
            'hero' => 'Black Widow',
            'sidekick' => 'Gareth',
            'lazy' => 'resolved',
        ], $repository->all());
        $this->assertSame('Black Widow', $repository->get('hero'));
        $this->assertSame('fallback', $repository->get('missing', 'fallback'));
    }

    public function testItReplacesAllValues(): void
    {
        $repository = new ArrayRepository(['name' => 'Sam']);

        $repository->set(['name' => 'Gareth']);

        $this->assertSame(['name' => 'Gareth'], $repository->all());
    }

    public function testItMayBeConditionallyChanged(): void
    {
        $repository = new ArrayRepository;

        $repository->when(true, fn (ArrayRepository $values) => $values->add('name', 'Gareth'));
        $repository->when(false, fn (ArrayRepository $values) => $values->add('name', 'Sam'));

        $this->assertSame(['name' => 'Gareth'], $repository->all());
    }

    public function testItReportsWhetherItIsEmpty(): void
    {
        $repository = new ArrayRepository;

        $this->assertTrue($repository->isEmpty());
        $this->assertFalse($repository->isNotEmpty());

        $repository->add('name', 'Sam');

        $this->assertFalse($repository->isEmpty());
        $this->assertTrue($repository->isNotEmpty());
    }
}
