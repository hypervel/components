<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Body;

use Hypervel\Saloon\Contracts\Body\MergeableBody;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Saloon\Repositories\Body\MultipartBodyRepository;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class MultipartBodyRepositoryTest extends TestCase
{
    public function testTheStoreIsEmptyByDefault(): void
    {
        $body = new MultipartBodyRepository;

        $this->assertSame([], $body->all());
    }

    public function testTheStoreCanHaveAnArrayOfMultipartValuesProvided(): void
    {
        $body = new MultipartBodyRepository([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('sidekick', 'Mantas'),
        ]);

        $this->assertEquals([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('sidekick', 'Mantas'),
        ], $body->all());
    }

    public function testTheStoreWillThrowAnExceptionIfSetValueIsNotAnArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The multipart body value must be an array.');

        $body = new MultipartBodyRepository;
        $body->set('123');
    }

    public function testTheStoreWillThrowAnExceptionIfTheArrayDoesNotContainMultipartValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The value array must only contain Hypervel\Saloon\Data\MultipartValue objects.');

        new MultipartBodyRepository([
            'name' => 'Sam',
            'sidekick' => new MultipartValue('username', 'Sammyjo20'),
        ]);
    }

    public function testYouCanSetIt(): void
    {
        $body = new MultipartBodyRepository([new MultipartValue('name', 'Sam')]);

        $body->set([
            new MultipartValue('username', 'Sammyjo20'),
        ]);

        $this->assertEquals([
            new MultipartValue('username', 'Sammyjo20'),
        ], $body->all());
    }

    public function testYouCanAddMultipleItems(): void
    {
        $body = new MultipartBodyRepository;

        $body->add('name', 'Sam', 'welcome.txt', ['a' => 'b']);

        $this->assertEquals([
            new MultipartValue('name', 'Sam', 'welcome.txt', ['a' => 'b']),
        ], $body->all());

        // Test it gets added to the array
        $body->add('name', 'Charlotte', 'welcome.txt', ['a' => 'b']);

        $this->assertEquals([
            new MultipartValue('name', 'Sam', 'welcome.txt', ['a' => 'b']),
            new MultipartValue('name', 'Charlotte', 'welcome.txt', ['a' => 'b']),
        ], $body->all());
    }

    public function testYouCanConditionallyAddItemsToTheArrayStore(): void
    {
        $body = new MultipartBodyRepository;

        $body->when(true, fn (MultipartBodyRepository $body): MultipartBodyRepository => $body->add('name', 'Gareth'));
        $body->when(false, fn (MultipartBodyRepository $body): MultipartBodyRepository => $body->add('name', 'Sam'));
        $body->when(true, fn (MultipartBodyRepository $body): MultipartBodyRepository => $body->add('sidekick', 'Mantas'));
        $body->when(false, fn (MultipartBodyRepository $body): MultipartBodyRepository => $body->add('sidekick', 'Teo'));

        $this->assertEquals([
            new MultipartValue('name', 'Gareth'),
            new MultipartValue('sidekick', 'Mantas'),
        ], $body->all());
    }

    public function testYouCanDeleteAnItem(): void
    {
        $body = new MultipartBodyRepository;

        $body->add('name', 'Sam');
        $body->add('name', 'Charlotte');
        $body->add('sidekick', 'Mantas');
        $body->remove('name');

        $this->assertEquals([new MultipartValue('sidekick', 'Mantas')], $body->all());
    }

    public function testYouCanGetAnItem(): void
    {
        $body = new MultipartBodyRepository;

        $body->add('name', 'Sam');
        $body->add('friend', 'Chris');

        $this->assertEquals(new MultipartValue('name', 'Sam'), $body->get('name'));
        $this->assertEquals(new MultipartValue('friend', 'Chris'), $body->get('friend'));
        $this->assertSame('fallback', $body->get('missing', 'fallback'));
    }

    public function testYouCanGetMultipleItemsWithTheSameName(): void
    {
        $body = new MultipartBodyRepository;

        $body->add('name', 'Sam');
        $body->add('name', 'Alex');

        $this->assertEquals([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('name', 'Alex'),
        ], $body->get('name'));
    }

    public function testYouCanGetAllItems(): void
    {
        $body = new MultipartBodyRepository;

        $body->add('name', 'Sam');
        $body->add('superhero', 'Iron Man');

        $this->assertEquals([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('superhero', 'Iron Man'),
        ], $body->all());
    }

    public function testYouCanMergeItemsTogetherIntoTheBodyRepository(): void
    {
        $body = new MultipartBodyRepository;

        $this->assertInstanceOf(MergeableBody::class, $body);

        $body->add('name', 'Sam');
        $body->add('sidekick', 'Mantas');

        $body->merge([new MultipartValue('sidekick', 'Gareth')], [new MultipartValue('superhero', 'Black Widow')]);

        $this->assertEquals([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('sidekick', 'Mantas'),
            new MultipartValue('sidekick', 'Gareth'),
            new MultipartValue('superhero', 'Black Widow'),
        ], $body->all());
    }

    public function testItWillThrowAnExceptionIfTheMergedItemsAreNotMultipartValueObjects(): void
    {
        $body = new MultipartBodyRepository;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The value array must only contain Hypervel\Saloon\Data\MultipartValue objects.');

        $body->merge([new MultipartValue('sidekick', 'Gareth')], ['superhero' => 'Black Widow']);
    }

    public function testYouCanCheckIfTheStoreIsEmptyOrNot(): void
    {
        $body = new MultipartBodyRepository;

        $this->assertTrue($body->isEmpty());
        $this->assertFalse($body->isNotEmpty());

        $body->add('name', 'Sam');

        $this->assertFalse($body->isEmpty());
        $this->assertTrue($body->isNotEmpty());
    }

    public function testItCreatesOneMultipartStreamWithTheConfiguredBoundary(): void
    {
        $body = new MultipartBodyRepository([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('count', 12),
            new MultipartValue('ratio', 1.5, 'ratio.txt', ['X-Part' => 'yes', 'X-Tags' => ['a', 'b']]),
        ], 'saloon-boundary');

        $contents = (string) $body->toStream();

        $this->assertSame('saloon-boundary', $body->getBoundary());
        $this->assertStringContainsString('name="name"', $contents);
        $this->assertStringContainsString("\r\n\r\nSam\r\n", $contents);
        $this->assertStringContainsString("\r\n\r\n12\r\n", $contents);
        $this->assertStringContainsString('filename="ratio.txt"', $contents);
        $this->assertStringContainsString("X-Part: yes\r\n", $contents);
        $this->assertStringContainsString("X-Tags: a, b\r\n", $contents);
        $this->assertStringContainsString("\r\n\r\n1.5\r\n", $contents);
    }
}
