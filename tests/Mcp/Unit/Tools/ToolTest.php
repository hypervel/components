<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Tools\ToolTest;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Attributes\Icon as IconAttribute;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Tools\Annotations\IsDestructive;
use Hypervel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Hypervel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Hypervel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Hypervel\Tests\Mcp\TestCase;

class ToolTest extends TestCase
{
    public function testTheDefaultNameIsInKebabCase(): void
    {
        $tool = new AnotherComplexToolName;
        $this->assertSame('another-complex-tool-name', $tool->name());
    }

    public function testTheNameMayBeTweaked(): void
    {
        $tool = new CustomToolName;

        $this->assertSame('my_custom_tool_name', $tool->name());
    }

    public function testReturnsNoAnnotationsByDefault(): void
    {
        $tool = new TestTool;
        $this->assertSame([], $tool->annotations());
    }

    public function testCanHaveACustomTitle(): void
    {
        $tool = new CustomTitleTool;
        $this->assertSame('Custom Title Tool', $tool->toArray()['title']);
    }

    public function testIncludesItsTitleInTheAnnotations(): void
    {
        $tool = new TestTool;
        $this->assertSame(['title' => 'Test Tool'], $tool->toArray()['annotations']);
    }

    public function testIncludesItsTitleNextToTheOtherAnnotations(): void
    {
        $tool = new KitchenSinkTool;
        $this->assertSame([
            'title' => 'The Kitchen Sink',
            'readOnlyHint' => true,
            'idempotentHint' => true,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ], $tool->toArray()['annotations']);
    }

    public function testLetsAToolOverrideTheAnnotationsTitle(): void
    {
        $tool = new class extends Tool {
            protected string $title = 'Top Level';

            /**
             * Override the annotation title.
             */
            public function annotations(): array
            {
                return ['title' => 'Annotated'];
            }
        };

        $this->assertSame('Top Level', $tool->toArray()['title']);
        $this->assertSame('Annotated', $tool->toArray()['annotations']['title']);
    }

    public function testCanBeReadOnly(): void
    {
        $tool = new ReadOnlyTool;
        $annotations = $tool->annotations();
        $this->assertTrue($annotations['readOnlyHint']);
    }

    public function testCanBeClosedWorld(): void
    {
        $tool = new ClosedWorldTool;
        $this->assertFalse($tool->annotations()['openWorldHint']);
    }

    public function testCanBeIdempotent(): void
    {
        $tool = new IdempotentTool;
        $annotations = $tool->annotations();
        $this->assertTrue($annotations['idempotentHint']);
    }

    public function testCanBeDestructive(): void
    {
        $tool = new DestructiveTool;
        $annotations = $tool->annotations();
        $this->assertTrue($annotations['destructiveHint']);
    }

    public function testIsNotDestructive(): void
    {
        $tool = new NotDestructiveTool;
        $annotations = $tool->annotations();
        $this->assertFalse($annotations['destructiveHint']);
    }

    public function testCanBeOpenWorld(): void
    {
        $tool = new OpenWorldTool;
        $this->assertTrue($tool->annotations()['openWorldHint']);
    }

    public function testCanHaveMultipleAnnotations(): void
    {
        $tool = new KitchenSinkTool;
        $this->assertSame([
            'readOnlyHint' => true,
            'idempotentHint' => true,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ], $tool->annotations());
    }

    public function testIncludesAnEmptyPropertiesObjectWhenTheSchemaHasNoProperties(): void
    {
        $tool = new TestTool;
        $array = $tool->toArray();

        $this->assertSame('object', $array['inputSchema']['type']);
        $this->assertEquals((object) [], $array['inputSchema']['properties']);
    }

    public function testIncludesSchemaPropertiesWhenDefined(): void
    {
        $tool = new ToolWithSchema;
        $array = $tool->toArray();

        $this->assertArrayHasKey('message', $array['inputSchema']['properties']);
        $this->assertSame('string', $array['inputSchema']['properties']['message']['type']);
        $this->assertSame('The message to echo', $array['inputSchema']['properties']['message']['description']);
        $this->assertSame(['message'], $array['inputSchema']['required']);
    }

    public function testCanHaveCustomMeta(): void
    {
        $tool = new CustomMetaTool;
        $this->assertSame(['key' => 'value'], $tool->toArray()['_meta']);
    }

    public function testOmitsIconsKeyWhenNoIconsAreDeclared(): void
    {
        $this->assertArrayNotHasKey('icons', (new TestTool)->toArray());
    }

    public function testIncludesIconsInToArrayWhenDeclared(): void
    {
        $array = (new ToolWithIcons)->toArray();

        $this->assertSame([
            ['src' => 'https://example.com/tool.png', 'mimeType' => 'image/png'],
        ], $array['icons']);
    }

    public function testIncludesIconsDeclaredViaTheIconAttribute(): void
    {
        $array = (new ToolWithIconAttribute)->toArray();

        $this->assertSame([
            ['src' => 'https://example.com/attribute.png', 'mimeType' => 'image/png'],
        ], $array['icons']);
    }

    public function testDefaultOutputSchemaReturnsEmptyArray(): void
    {
        $tool = new ToolWithoutOutputSchema;
        $array = $tool->toArray();

        $this->assertArrayNotHasKey('outputSchema', $array);
    }

    public function testOutputSchemaCanBeOverriddenToReturnCustomSchema(): void
    {
        $tool = new ToolWithOutputSchema;
        $array = $tool->toArray();

        $this->assertArrayHasKey('outputSchema', $array);
        $this->assertArrayHasKey('result', $array['outputSchema']['properties']);
        $this->assertArrayHasKey('count', $array['outputSchema']['properties']);
    }
}

class TestTool extends Tool
{
    /**
     * Return the tool description.
     */
    public function description(): string
    {
        return 'A test tool';
    }

    /**
     * Return the tool result.
     */
    public function handle(): Response
    {
        return Response::text('test');
    }
}

class CustomTitleTool extends TestTool
{
    protected string $title = 'Custom Title Tool';
}

#[IsReadOnly]
class ReadOnlyTool extends TestTool
{
}

#[IsOpenWorld(false)]
class ClosedWorldTool extends TestTool
{
}

#[IsIdempotent]
class IdempotentTool extends TestTool
{
}

#[IsDestructive]
class DestructiveTool extends TestTool
{
}

#[IsDestructive(false)]
class NotDestructiveTool extends TestTool
{
}

#[IsOpenWorld]
class OpenWorldTool extends TestTool
{
}

#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class KitchenSinkTool extends TestTool
{
    protected string $title = 'The Kitchen Sink';
}

class AnotherComplexToolName extends TestTool
{
}

class CustomToolName extends TestTool
{
    protected string $name = 'my_custom_tool_name';
}

class ToolWithSchema extends TestTool
{
    /**
     * Define the input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()->description('The message to echo')->required(),
        ];
    }
}

class CustomMetaTool extends TestTool
{
    protected ?array $meta = [
        'key' => 'value',
    ];
}

class ToolWithIcons extends TestTool
{
    /**
     * Return the tool icons.
     */
    public function icons(): array
    {
        return [new Icon('https://example.com/tool.png', mimeType: 'image/png')];
    }
}

#[IconAttribute('https://example.com/attribute.png', mimeType: 'image/png')]
class ToolWithIconAttribute extends TestTool
{
}

class ToolWithOutputSchema extends TestTool
{
    /**
     * Define the output schema.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'result' => $schema->string()->description('The result value')->required(),
            'count' => $schema->integer()->description('The count value')->required(),
        ];
    }
}

class ToolWithoutOutputSchema extends TestTool
{
}
