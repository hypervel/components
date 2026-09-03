<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation;

use Hypervel\Data\Support\Partials\PartialDefinition;
use Hypervel\Data\Support\Transformation\PartialTree;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Wrapping\WrapExecutionType;
use Hypervel\Tests\TestCase;

class TransformationContextTest extends TestCase
{
    /**
     * Test resolved instance partials compose with narrowed parent selections.
     */
    public function testMergesResolvedPartialsIntoTheCurrentSelection(): void
    {
        $context = new TransformationContext(
            include: PartialTree::compile(['parent']),
            only: PartialTree::compile(['*']),
        );

        $merged = $context->withMergedPartials([
            'include' => [new PartialDefinition('instance')],
            'exclude' => [new PartialDefinition('secret')],
            'only' => [new PartialDefinition('instance')],
            'except' => [new PartialDefinition('password')],
        ]);

        $this->assertNotSame($context, $merged);
        $this->assertTrue($merged->include?->selects('parent'));
        $this->assertTrue($merged->include?->selects('instance'));
        $this->assertTrue($merged->exclude?->selects('secret'));
        $this->assertTrue($merged->except?->selects('password'));
        $this->assertTrue($merged->only?->all);
        $this->assertSame(['instance'], array_keys($merged->only?->children ?? []));
    }

    /**
     * Test child contexts narrow trees and discard root-relative definitions.
     */
    public function testChildClearsRootRelativePartialDefinitions(): void
    {
        $context = new TransformationContext(
            transformValues: false,
            include: PartialTree::compile(['nested.value']),
            partialDefinitions: [
                'include' => [new PartialDefinition('nested.value')],
                'exclude' => [],
                'only' => [],
                'except' => [],
            ],
            depth: 2,
            maxDepth: 5,
        );

        $child = $context->child('nested');

        $this->assertFalse($child->transformValues);
        $this->assertTrue($child->include?->selects('value'));
        $this->assertSame([], $child->partialDefinitions);
        $this->assertSame(3, $child->depth);
        $this->assertSame(5, $child->maxDepth);
    }

    public function testConstructableViewSurvivesEveryContextCopy(): void
    {
        $context = new TransformationContext(constructable: true);

        $merged = $context->withMergedPartials([
            'include' => [new PartialDefinition('nested')],
            'exclude' => [],
            'only' => [],
            'except' => [],
        ]);
        $wrapped = $merged->withWrapExecutionType(WrapExecutionType::Enabled);
        $child = $wrapped->child('nested');

        $this->assertTrue($merged->constructable);
        $this->assertTrue($wrapped->constructable);
        $this->assertTrue($child->constructable);
    }
}
