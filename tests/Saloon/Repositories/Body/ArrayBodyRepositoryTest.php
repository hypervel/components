<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Repositories\Body;

use Hypervel\Saloon\Contracts\Body\MergeableBody;
use Hypervel\Saloon\Repositories\Body\FormBodyRepository;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ArrayBodyRepositoryTest extends TestCase
{
    public function testItStoresAndReplacesArrayValues(): void
    {
        $body = new FormBodyRepository([
            'name' => 'Sam',
            'sidekick' => 'Mantas',
        ]);

        $this->assertSame(['name' => 'Sam', 'sidekick' => 'Mantas'], $body->all());

        $body->set(['name' => 'Gareth']);

        $this->assertSame(['name' => 'Gareth'], $body->all());
    }

    public function testItRejectsNonArrayValues(): void
    {
        $body = new FormBodyRepository;

        $this->expectException(InvalidArgumentException::class);

        $body->set('Sam');
    }

    public function testItAddsGetsAndRemovesValues(): void
    {
        $body = new FormBodyRepository;

        $body->add('name', 'Sam');
        $body->add(1, 'Mantas');
        $body->add(value: 'Gareth');

        $this->assertSame('Sam', $body->get('name'));
        $this->assertSame('Mantas', $body->get(1));
        $this->assertSame('fallback', $body->get('missing', 'fallback'));
        $this->assertSame([
            'name' => 'Sam',
            1 => 'Mantas',
            2 => 'Gareth',
        ], $body->get());

        $body->remove('name');
        $body->remove(1);

        $this->assertSame([2 => 'Gareth'], $body->all());
    }

    public function testItMergesAndConditionallyChangesValues(): void
    {
        $body = new FormBodyRepository(['name' => 'Sam']);

        $this->assertInstanceOf(MergeableBody::class, $body);

        $body->merge(['name' => 'Gareth'], ['sidekick' => 'Mantas']);
        $body->when(true, fn (FormBodyRepository $repository) => $repository->add('hero', 'Black Widow'));
        $body->when(false, fn (FormBodyRepository $repository) => $repository->add('hero', 'Iron Man'));

        $this->assertSame([
            'name' => 'Gareth',
            'sidekick' => 'Mantas',
            'hero' => 'Black Widow',
        ], $body->all());
    }

    public function testItReportsWhetherItIsEmpty(): void
    {
        $body = new FormBodyRepository;

        $this->assertTrue($body->isEmpty());
        $this->assertFalse($body->isNotEmpty());

        $body->add('name', 'Sam');

        $this->assertFalse($body->isEmpty());
        $this->assertTrue($body->isNotEmpty());
    }
}
