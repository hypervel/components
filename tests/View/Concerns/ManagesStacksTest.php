<?php

declare(strict_types=1);

namespace Hypervel\Tests\View\Concerns;

use Hypervel\Tests\TestCase;
use Hypervel\View\Concerns\ManagesStacks;
use InvalidArgumentException;

class ManagesStacksTest extends TestCase
{
    public function testStackIsEmpty(): void
    {
        $this->assertTrue((new FakeViewFactory)->isStackEmpty('my-stack'));
    }

    public function testStackIsNotEmptyWithPushedContent(): void
    {
        $object = new FakeViewFactory;
        $object->startPush('my-stack', 'some pushed content');

        $this->assertFalse($object->isStackEmpty('my-stack'));
    }

    public function testStackIsNotEmptyWithPrependedContent(): void
    {
        $object = new FakeViewFactory;
        $object->startPrepend('my-stack', 'some prepended content');

        $this->assertFalse($object->isStackEmpty('my-stack'));
    }

    public function testStopPushRequiresAStartedPush(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot end a push stack without first starting one.');

        (new FakeViewFactory)->stopPush();
    }

    public function testStopPrependRequiresAStartedPrepend(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot end a prepend operation without first starting one.');

        (new FakeViewFactory)->stopPrepend();
    }

    public function testFalsyStackNamesCanBeStopped(): void
    {
        $factory = new FakeViewFactory;
        $factory->startPush('0');

        $this->assertSame('0', $factory->stopPush());

        $factory->startPrepend('');

        $this->assertSame('', $factory->stopPrepend());
    }
}

class FakeViewFactory
{
    use ManagesStacks;

    protected function getRenderCount(): int
    {
        return 0;
    }
}
