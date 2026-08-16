<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Repositories\Body;

use Hypervel\Saloon\Repositories\Body\StringBodyRepository;
use Hypervel\Tests\TestCase;

class StringBodyRepositoryTest extends TestCase
{
    public function testItIsEmptyByDefault(): void
    {
        $body = new StringBodyRepository;

        $this->assertNull($body->all());
        $this->assertTrue($body->isEmpty());
        $this->assertFalse($body->isNotEmpty());
        $this->assertSame('', (string) $body);
        $this->assertSame('', (string) $body->toStream());
    }

    public function testItStoresAndReplacesStringBodies(): void
    {
        $body = new StringBodyRepository('Sam');

        $this->assertSame('Sam', $body->all());

        $body->set('Yeehaw!');

        $this->assertSame('Yeehaw!', $body->all());
        $this->assertFalse($body->isEmpty());
        $this->assertTrue($body->isNotEmpty());
        $this->assertSame('Yeehaw!', (string) $body->toStream());
    }

    public function testZeroIsNotAnEmptyBody(): void
    {
        $body = new StringBodyRepository('0');

        $this->assertFalse($body->isEmpty());
        $this->assertSame('0', (string) $body->toStream());
    }

    public function testItMayBeConditionallyChanged(): void
    {
        $body = new StringBodyRepository;

        $body->when(true, fn (StringBodyRepository $repository) => $repository->set('Gareth'));
        $body->when(false, fn (StringBodyRepository $repository) => $repository->set('Sam'));

        $this->assertSame('Gareth', $body->all());
    }
}
