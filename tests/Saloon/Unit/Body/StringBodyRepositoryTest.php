<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Body;

use Hypervel\Saloon\Repositories\Body\StringBodyRepository;
use Hypervel\Tests\TestCase;

class StringBodyRepositoryTest extends TestCase
{
    public function testTheStoreIsEmptyByDefault(): void
    {
        $body = new StringBodyRepository;

        $this->assertNull($body->all());
        $this->assertSame('', (string) $body);
        $this->assertSame('', (string) $body->toStream());
    }

    public function testTheStoreCanHaveADefaultStringProvided(): void
    {
        $body = new StringBodyRepository('Yeehaw!');

        $this->assertSame('Yeehaw!', $body->all());
    }

    public function testYouCanSetIt(): void
    {
        $body = new StringBodyRepository('Sam');

        $body->set('Yeehaw!');

        $this->assertSame('Yeehaw!', $body->all());
        $this->assertSame('Yeehaw!', (string) $body->toStream());
    }

    public function testYouCanConditionallySetOnTheStore(): void
    {
        $body = new StringBodyRepository;

        $body->when(true, fn (StringBodyRepository $body): StringBodyRepository => $body->set('Gareth'));
        $body->when(false, fn (StringBodyRepository $body): StringBodyRepository => $body->set('Sam'));

        $this->assertSame('Gareth', $body->all());
    }

    public function testYouCanCheckIfTheStoreIsEmptyOrNot(): void
    {
        $body = new StringBodyRepository;

        $this->assertTrue($body->isEmpty());
        $this->assertFalse($body->isNotEmpty());

        $body->set('Sam');

        $this->assertFalse($body->isEmpty());
        $this->assertTrue($body->isNotEmpty());
    }

    public function testZeroIsNotAnEmptyBody(): void
    {
        $body = new StringBodyRepository('0');

        $this->assertFalse($body->isEmpty());
        $this->assertSame('0', (string) $body->toStream());
    }
}
