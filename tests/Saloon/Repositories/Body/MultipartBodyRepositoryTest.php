<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Repositories\Body;

use Hypervel\Saloon\Contracts\Body\MergeableBody;
use Hypervel\Saloon\Data\MultipartValue;
use Hypervel\Saloon\Repositories\Body\MultipartBodyRepository;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class MultipartBodyRepositoryTest extends TestCase
{
    public function testItIsEmptyByDefault(): void
    {
        $body = new MultipartBodyRepository;

        $this->assertSame([], $body->all());
        $this->assertTrue($body->isEmpty());
        $this->assertFalse($body->isNotEmpty());
    }

    public function testItStoresAddsMergesAndRemovesValues(): void
    {
        $body = new MultipartBodyRepository([
            new MultipartValue('name', 'Sam'),
        ]);

        $this->assertInstanceOf(MergeableBody::class, $body);

        $body->add('name', 'Charlotte', 'welcome.txt', ['X-Test' => 'yes']);
        $body->merge([new MultipartValue('sidekick', 'Mantas')]);

        $this->assertEquals([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('name', 'Charlotte', 'welcome.txt', ['X-Test' => 'yes']),
            new MultipartValue('sidekick', 'Mantas'),
        ], $body->all());
        $this->assertEquals([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('name', 'Charlotte', 'welcome.txt', ['X-Test' => 'yes']),
        ], $body->get('name'));
        $this->assertEquals(new MultipartValue('sidekick', 'Mantas'), $body->get('sidekick'));
        $this->assertSame('fallback', $body->get('missing', 'fallback'));

        $body->remove('name');

        $this->assertEquals([new MultipartValue('sidekick', 'Mantas')], $body->all());
    }

    public function testItMayBeConditionallyChanged(): void
    {
        $body = new MultipartBodyRepository;

        $body->when(true, fn (MultipartBodyRepository $repository) => $repository->add('name', 'Gareth'));
        $body->when(false, fn (MultipartBodyRepository $repository) => $repository->add('name', 'Sam'));

        $this->assertEquals([new MultipartValue('name', 'Gareth')], $body->all());
    }

    public function testItRejectsNonArrayRepositoryValues(): void
    {
        $body = new MultipartBodyRepository;

        $this->expectException(InvalidArgumentException::class);

        $body->set('invalid');
    }

    public function testItRejectsArraysContainingOtherValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MultipartBodyRepository([
            new MultipartValue('name', 'Sam'),
            'invalid',
        ]);
    }

    public function testItRejectsNonFiniteNumericValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MultipartValue('limit', INF);
    }

    public function testItCreatesOneMultipartStreamWithTheConfiguredBoundary(): void
    {
        $body = new MultipartBodyRepository([
            new MultipartValue('name', 'Sam'),
            new MultipartValue('count', 12),
            new MultipartValue('ratio', 1.5, 'ratio.txt', ['X-Part' => 'yes']),
        ], 'saloon-boundary');

        $contents = (string) $body->toStream();

        $this->assertSame('saloon-boundary', $body->boundary());
        $this->assertStringContainsString('name="name"', $contents);
        $this->assertStringContainsString("\r\n\r\nSam\r\n", $contents);
        $this->assertStringContainsString("\r\n\r\n12\r\n", $contents);
        $this->assertStringContainsString('filename="ratio.txt"', $contents);
        $this->assertStringContainsString("X-Part: yes\r\n", $contents);
        $this->assertStringContainsString("\r\n\r\n1.5\r\n", $contents);
    }
}
