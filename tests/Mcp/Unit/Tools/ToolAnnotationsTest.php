<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Tools;

use Hypervel\Mcp\Enums\Role;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Annotations\Audience;
use Hypervel\Mcp\Server\Annotations\Priority;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ToolAnnotationsTest extends TestCase
{
    public function testAcceptsValidToolAnnotations(): void
    {
        $tool = new #[IsReadOnly] class extends Tool {
            /**
             * Return the tool result.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $annotations = $tool->annotations();

        $this->assertArrayHasKey('readOnlyHint', $annotations);
        $this->assertTrue($annotations['readOnlyHint']);
    }

    public function testRejectsResourceAnnotationsOnTools(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Annotation [Hypervel\Mcp\Server\Annotations\Audience] cannot be used on');
        $tool = new #[Audience(Role::Assistant)] class extends Tool {
            /**
             * Return the tool result.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $tool->annotations();
    }

    public function testRejectsPriorityAnnotationOnTools(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Annotation [Hypervel\Mcp\Server\Annotations\Priority] cannot be used on');
        $tool = new #[Priority(0.8)] class extends Tool {
            /**
             * Return the tool result.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $tool->annotations();
    }

    public function testAcceptsMultipleToolAnnotations(): void
    {
        $tool = new #[IsReadOnly]
        #[IsIdempotent] class extends Tool {
            /**
             * Return the tool result.
             */
            public function handle(Request $request): Response
            {
                return Response::text('test');
            }
        };

        $annotations = $tool->annotations();

        $this->assertArrayHasKey('readOnlyHint', $annotations);
        $this->assertArrayHasKey('idempotentHint', $annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertTrue($annotations['idempotentHint']);
    }
}
