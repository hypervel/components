<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Http\Request;
use Hypervel\Inertia\MergeProp;

class MergePropTest extends TestCase
{
    public function testCanInvokeWithACallback(): void
    {
        $mergeProp = new MergeProp(function () {
            return 'A merge prop value';
        });

        $this->assertSame('A merge prop value', $mergeProp());
    }

    public function testCanInvokeWithANonCallback(): void
    {
        $mergeProp = new MergeProp(['key' => 'value']);

        $this->assertSame(['key' => 'value'], $mergeProp());
    }

    public function testStringFunctionNamesAreNotInvoked(): void
    {
        $mergeProp = new MergeProp('date');

        $this->assertSame('date', $mergeProp());
    }

    public function testCanResolveBindingsWhenInvoked(): void
    {
        $mergeProp = new MergeProp(function (Request $request) {
            return $request;
        });

        $this->assertInstanceOf(Request::class, $mergeProp());
    }

    public function testAppendsByDefault(): void
    {
        $mergeProp = new MergeProp([]);

        $this->assertTrue($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame([], $mergeProp->appendsAtPaths());
        $this->assertSame([], $mergeProp->prependsAtPaths());
        $this->assertSame([], $mergeProp->matchesOn());
    }

    public function testPrepends(): void
    {
        $mergeProp = (new MergeProp([]))->prepend();

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertTrue($mergeProp->prependsAtRoot());
        $this->assertSame([], $mergeProp->appendsAtPaths());
        $this->assertSame([], $mergeProp->prependsAtPaths());
        $this->assertSame([], $mergeProp->matchesOn());
    }

    public function testAppendsWithNestedMergePaths(): void
    {
        $mergeProp = (new MergeProp([]))->append('data');

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame(['data'], $mergeProp->appendsAtPaths());
        $this->assertSame([], $mergeProp->prependsAtPaths());
        $this->assertSame([], $mergeProp->matchesOn());
    }

    public function testAppendsWithNestedMergePathsAndMatchOn(): void
    {
        $mergeProp = (new MergeProp([]))->append('data', 'id');

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame(['data'], $mergeProp->appendsAtPaths());
        $this->assertSame([], $mergeProp->prependsAtPaths());
        $this->assertSame(['data.id'], $mergeProp->matchesOn());
    }

    public function testPrependsWithNestedMergePaths(): void
    {
        $mergeProp = (new MergeProp([]))->prepend('data');

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame([], $mergeProp->appendsAtPaths());
        $this->assertSame(['data'], $mergeProp->prependsAtPaths());
        $this->assertSame([], $mergeProp->matchesOn());
    }

    public function testPrependsWithNestedMergePathsAndMatchOn(): void
    {
        $mergeProp = (new MergeProp([]))->prepend('data', 'id');

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame([], $mergeProp->appendsAtPaths());
        $this->assertSame(['data'], $mergeProp->prependsAtPaths());
        $this->assertSame(['data.id'], $mergeProp->matchesOn());
    }

    public function testAppendWithNestedMergePathsAsArray(): void
    {
        $mergeProp = (new MergeProp([]))->append(['data', 'items']);

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame(['data', 'items'], $mergeProp->appendsAtPaths());
        $this->assertSame([], $mergeProp->prependsAtPaths());
        $this->assertSame([], $mergeProp->matchesOn());
    }

    public function testAppendWithNestedMergePathsAndMatchOnAsArray(): void
    {
        $mergeProp = (new MergeProp([]))->append(['data' => 'id', 'items' => 'uid']);

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame(['data', 'items'], $mergeProp->appendsAtPaths());
        $this->assertSame([], $mergeProp->prependsAtPaths());
        $this->assertSame(['data.id', 'items.uid'], $mergeProp->matchesOn());
    }

    public function testPrependWithNestedMergePathsAsArray(): void
    {
        $mergeProp = (new MergeProp([]))->prepend(['data', 'items']);

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame([], $mergeProp->appendsAtPaths());
        $this->assertSame(['data', 'items'], $mergeProp->prependsAtPaths());
        $this->assertSame([], $mergeProp->matchesOn());
    }

    public function testPrependWithNestedMergePathsAndMatchOnAsArray(): void
    {
        $mergeProp = (new MergeProp([]))->prepend(['data' => 'id', 'items' => 'uid']);

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame([], $mergeProp->appendsAtPaths());
        $this->assertSame(['data', 'items'], $mergeProp->prependsAtPaths());
        $this->assertSame(['data.id', 'items.uid'], $mergeProp->matchesOn());
    }

    public function testMixOfAppendAndPrependWithNestedMergePathsAndMatchOnAsArray(): void
    {
        $mergeProp = (new MergeProp([]))
            ->append('data')
            ->append('users', 'id')
            ->append(['items' => 'uid', 'posts'])
            ->prepend('categories')
            ->prepend('companies', 'id')
            ->prepend(['tags' => 'name', 'comments']);

        $this->assertFalse($mergeProp->appendsAtRoot());
        $this->assertFalse($mergeProp->prependsAtRoot());
        $this->assertSame(['data', 'users', 'items', 'posts'], $mergeProp->appendsAtPaths());
        $this->assertSame(['categories', 'companies', 'tags', 'comments'], $mergeProp->prependsAtPaths());
        $this->assertSame(['users.id', 'items.uid', 'companies.id', 'tags.name'], $mergeProp->matchesOn());
    }

    public function testIsOnceable(): void
    {
        $mergeProp = (new MergeProp(fn () => []))
            ->once()
            ->as('custom-key')
            ->until(60);

        $this->assertTrue($mergeProp->shouldResolveOnce());
        $this->assertSame('custom-key', $mergeProp->getKey());
        $this->assertNotNull($mergeProp->expiresAt());
    }
}
