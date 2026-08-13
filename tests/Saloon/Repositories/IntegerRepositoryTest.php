<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Repositories;

use Hypervel\Saloon\Repositories\IntegerRepository;
use Hypervel\Tests\TestCase;

class IntegerRepositoryTest extends TestCase
{
    public function testRepositoryIsEmptyByDefault(): void
    {
        $repository = new IntegerRepository;

        $this->assertNull($repository->get());
        $this->assertTrue($repository->isEmpty());
        $this->assertFalse($repository->isNotEmpty());
    }

    public function testRepositoryCanBeConstructedWithAValue(): void
    {
        $repository = new IntegerRepository(1);

        $this->assertSame(1, $repository->get());
    }

    public function testValueCanBeSet(): void
    {
        $repository = new IntegerRepository;

        $this->assertSame($repository, $repository->set(1));
        $this->assertSame(1, $repository->get());
    }

    public function testZeroIsNotEmpty(): void
    {
        $repository = new IntegerRepository(0);

        $this->assertFalse($repository->isEmpty());
        $this->assertTrue($repository->isNotEmpty());
    }

    public function testRepositoryIsConditionable(): void
    {
        $repository = (new IntegerRepository)
            ->when(true, fn (IntegerRepository $repository) => $repository->set(1));

        $this->assertSame(1, $repository->get());
    }
}
