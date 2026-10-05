<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Body;

use Hypervel\Saloon\Contracts\Body\MergeableBody;
use Hypervel\Saloon\Repositories\Body\FormBodyRepository;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

// ArrayBodyRepository is abstract because it cannot be converted to a stream, so its behavior is tested through
// FormBodyRepository.
class ArrayBodyRepositoryTest extends TestCase
{
    public function testTheStoreIsEmptyByDefault(): void
    {
        $body = new FormBodyRepository;

        $this->assertSame([], $body->all());
    }

    public function testTheStoreCanHaveAnArrayProvided(): void
    {
        $body = new FormBodyRepository([
            'name' => 'Sam',
            'sidekick' => 'Mantas',
        ]);

        $this->assertSame([
            'name' => 'Sam',
            'sidekick' => 'Mantas',
        ], $body->all());
    }

    public function testYouCanSetIt(): void
    {
        $body = new FormBodyRepository(['sidekick' => 'Mantas']);

        $body->set(['name' => 'Sam']);

        $this->assertSame(['name' => 'Sam'], $body->all());
    }

    public function testItWillThrowAnExceptionIfYouSetANonArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The body value must be an array.');

        $body = new FormBodyRepository;
        $body->set('Sam');
    }

    public function testYouCanAddAnItem(): void
    {
        $body = new FormBodyRepository;

        $body->add('name', 'Sam');

        $this->assertSame(['name' => 'Sam'], $body->all());
    }

    public function testYouCanAddAnItemWithAnIntegerKey(): void
    {
        $body = new FormBodyRepository;

        $body->add(1, 'Sam');

        $this->assertSame([1 => 'Sam'], $body->all());
    }

    public function testYouCanAddAnItemWithoutAKey(): void
    {
        $body = new FormBodyRepository;

        $body->add(null, 'Sam');

        $this->assertSame(['Sam'], $body->all());

        $body->add(5, 'Mantas');
        $body->add(value: 'Gareth');

        $this->assertSame([0 => 'Sam', 5 => 'Mantas', 6 => 'Gareth'], $body->all());
    }

    public function testYouCanConditionallyAddItemsToTheArrayStore(): void
    {
        $body = new FormBodyRepository;

        $body->when(true, fn (FormBodyRepository $body): FormBodyRepository => $body->add('name', 'Gareth'));
        $body->when(false, fn (FormBodyRepository $body): FormBodyRepository => $body->add('name', 'Sam'));
        $body->when(true, fn (FormBodyRepository $body): FormBodyRepository => $body->add('sidekick', 'Mantas'));
        $body->when(false, fn (FormBodyRepository $body): FormBodyRepository => $body->add('sidekick', 'Teo'));

        $this->assertSame(['name' => 'Gareth', 'sidekick' => 'Mantas'], $body->all());
    }

    public function testYouCanDeleteAnItem(): void
    {
        $body = new FormBodyRepository;

        $body->add('name', 'Sam');
        $body->remove('name');

        $this->assertSame([], $body->all());
    }

    public function testYouCanDeleteAnItemWithAnIntegerKey(): void
    {
        $body = new FormBodyRepository;

        $body->add(1, 'Sam');
        $body->add(2, 'Gareth');
        $body->remove(1);

        $this->assertSame([2 => 'Gareth'], $body->all());
    }

    public function testYouCanGetAnItem(): void
    {
        $body = new FormBodyRepository;

        $body->add('name', 'Sam');

        $this->assertSame('Sam', $body->get('name'));
        $this->assertSame('fallback', $body->get('missing', 'fallback'));

        // When omitting the key it should act like `->all()`
        $this->assertSame(['name' => 'Sam'], $body->get());
    }

    public function testYouCanGetAnItemWithAnIntegerKey(): void
    {
        $body = new FormBodyRepository;

        $body->add(2, 'Sam');

        $this->assertSame('Sam', $body->get(2));
    }

    public function testYouCanGetAllItems(): void
    {
        $body = new FormBodyRepository;

        $body->add('name', 'Sam');
        $body->add('superhero', 'Iron Man');

        $this->assertSame(['name' => 'Sam', 'superhero' => 'Iron Man'], $body->all());
    }

    public function testYouCanMergeItemsTogetherIntoTheBodyRepository(): void
    {
        $body = new FormBodyRepository;

        $this->assertInstanceOf(MergeableBody::class, $body);

        $body->add('name', 'Sam');
        $body->add('sidekick', 'Mantas');

        $body->merge(['sidekick' => 'Gareth'], ['superhero' => 'Black Widow']);

        $this->assertSame(['name' => 'Sam', 'sidekick' => 'Gareth', 'superhero' => 'Black Widow'], $body->all());
    }

    public function testYouCanCheckIfTheStoreIsEmptyOrNot(): void
    {
        $body = new FormBodyRepository;

        $this->assertTrue($body->isEmpty());
        $this->assertFalse($body->isNotEmpty());

        $body->add('name', 'Sam');

        $this->assertFalse($body->isEmpty());
        $this->assertTrue($body->isNotEmpty());
    }
}
