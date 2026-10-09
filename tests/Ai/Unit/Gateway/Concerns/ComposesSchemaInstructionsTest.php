<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Concerns;

use Hypervel\Ai\Gateway\Concerns\ComposesSchemaInstructions;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Tests\TestCase;

class ComposesSchemaInstructionsTest extends TestCase
{
    use ComposesSchemaInstructions;

    protected JsonSchemaTypeFactory $factory;

    /**
     * Set up the schema factory.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new JsonSchemaTypeFactory;
    }

    public function testReturnsInstructionsUnchangedWhenSchemaIsBlank(): void
    {
        $this->assertSame('Be helpful.', $this->composeInstructions('Be helpful.', null));
    }

    public function testReturnsNullWhenBothInstructionsAndSchemaAreBlank(): void
    {
        $this->assertNull($this->composeInstructions(null, null));
    }

    public function testAppendsSchemaInstructionWhenInstructionsAreBlank(): void
    {
        $schema = ['name' => $this->factory->string()->description('The name')];

        $result = $this->composeInstructions(null, $schema);

        $this->assertStringContainsString('JSON object', $result);
    }

    public function testPrependsInstructionsBeforeSchemaInstruction(): void
    {
        $schema = ['name' => $this->factory->string()->description('The name')];

        $result = $this->composeInstructions('Be concise.', $schema);

        $this->assertStringStartsWith('Be concise.', $result);
        $this->assertStringContainsString('JSON object', $result);
    }

    public function testNonAsciiCharactersInSchemaDescriptionsAreNotUnicodeEscaped(): void
    {
        $schema = ['name' => $this->factory->string()->description('名前を入力してください')];

        $result = $this->composeInstructions(null, $schema);

        $this->assertStringContainsString('名前を入力してください', $result);
        $this->assertStringNotContainsString('\u', $result);
    }
}
