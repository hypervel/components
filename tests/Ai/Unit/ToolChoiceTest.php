<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit;

use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\ToolChoice;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ToolChoiceTest extends TestCase
{
    public function testConstructorAndToolBuildTheExpectedModeAndToolName(): void
    {
        $this->assertSame(ToolChoice::auto, (new ToolChoice(ToolChoice::auto))->mode);
        $this->assertNull((new ToolChoice(ToolChoice::auto))->toolName);
        $this->assertSame(ToolChoice::none, (new ToolChoice(ToolChoice::none))->mode);
        $this->assertSame(ToolChoice::required, (new ToolChoice(ToolChoice::required))->mode);
        $this->assertSame(ToolChoice::tool, ToolChoice::tool('calculator')->mode);
        $this->assertSame('calculator', ToolChoice::tool('calculator')->toolName);
    }

    #[DataProvider('emptyToolNames')]
    public function testToolModeRequiresANonEmptyToolName(?string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolChoice(ToolChoice::tool, $name);
    }

    /**
     * Provide missing and empty tool names.
     */
    public static function emptyToolNames(): array
    {
        return [
            'missing tool name' => [null],
            'empty tool name' => [''],
        ];
    }

    #[DataProvider('nonToolModes')]
    public function testNonToolModesRejectAToolName(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolChoice($mode, 'x');
    }

    /**
     * Provide modes that reject a tool name.
     */
    public static function nonToolModes(): array
    {
        return [
            'auto' => [ToolChoice::auto],
            'required' => [ToolChoice::required],
        ];
    }

    public function testFromCoercesInstancesAndStrings(): void
    {
        $choice = new ToolChoice(ToolChoice::required);

        $this->assertSame($choice, ToolChoice::from($choice));
        $this->assertSame(ToolChoice::auto, ToolChoice::from('auto')->mode);
        $this->assertSame(ToolChoice::required, ToolChoice::from('required')->mode);
    }

    public function testFromAcceptsArrayShorthandForToolSelection(): void
    {
        $this->assertSame('calculator', ToolChoice::from(['tool' => 'calculator'])->toolName);
        $this->assertSame('calculator', ToolChoice::from(['toolName' => 'calculator'])->toolName);
        $this->assertSame('calculator', ToolChoice::from(['name' => 'calculator'])->toolName);
    }

    #[DataProvider('invalidValues')]
    public function testFromRejectsInvalidValues(string|array $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        ToolChoice::from($value);
    }

    /**
     * Provide invalid tool choice values.
     */
    public static function invalidValues(): array
    {
        return [['bogus'], [['unexpected' => 'value']]];
    }

    public function testOptionsResolveToolChoiceFromTheAttribute(): void
    {
        $options = TextGenerationOptions::forAgent(new AttributeToolChoiceAgent);

        $this->assertNotNull($options->toolChoice);
        $this->assertSame(ToolChoice::required, $options->toolChoice->mode);
    }

    public function testOptionsResolveToolChoiceFromTheMethodOverTheAttribute(): void
    {
        $options = TextGenerationOptions::forAgent(new ToolChoiceAgent(ToolChoice::tool('custom_named_tool')));

        $this->assertSame(ToolChoice::tool, $options->toolChoice->mode);
        $this->assertSame('custom_named_tool', $options->toolChoice->toolName);
    }

    public function testOptionsCoerceAPlainStringFromTheMethod(): void
    {
        $options = TextGenerationOptions::forAgent(new ToolChoiceAgent('required'));

        $this->assertSame(ToolChoice::required, $options->toolChoice->mode);
    }

    public function testOptionsLeaveToolChoiceNullWhenTheAgentDeclaresNone(): void
    {
        $this->assertNull(TextGenerationOptions::forAgent(new AssistantAgent)->toolChoice);
        $this->assertNull(TextGenerationOptions::forAgent(new ToolChoiceAgent)->toolChoice);
    }

    public function testForStepReleasesAForcedToolChoiceAfterTheFirstStep(): void
    {
        foreach ([ToolChoice::required, ToolChoice::tool] as $mode) {
            $options = new TextGenerationOptions(
                toolChoice: $mode === ToolChoice::tool ? ToolChoice::tool('calculator') : new ToolChoice($mode),
            );

            $this->assertSame($options, $options->forStep(0));
            $this->assertSame($mode, $options->forStep(0)->toolChoice->mode);
            $this->assertNull($options->forStep(1)->toolChoice);
            $this->assertNull($options->forStep(2)->toolChoice);
        }
    }

    public function testForStepKeepsAutoAndNoneToolChoicesOnEveryStep(): void
    {
        foreach ([ToolChoice::auto, ToolChoice::none] as $mode) {
            $options = new TextGenerationOptions(toolChoice: new ToolChoice($mode));

            $this->assertSame($mode, $options->forStep(0)->toolChoice->mode);
            $this->assertSame($mode, $options->forStep(3)->toolChoice->mode);
        }
    }

    public function testForStepPreservesOtherOptionsWhenReleasingTheToolChoice(): void
    {
        $options = new TextGenerationOptions(
            maxSteps: 4,
            maxTokens: 256,
            temperature: 0.7,
            topP: 0.9,
            toolChoice: new ToolChoice(ToolChoice::required),
        );

        $stepped = $options->forStep(1);

        $this->assertNull($stepped->toolChoice);
        $this->assertSame(4, $stepped->maxSteps);
        $this->assertSame(256, $stepped->maxTokens);
        $this->assertSame(0.7, $stepped->temperature);
        $this->assertSame(0.9, $stepped->topP);
    }

    public function testForStepIsANoOpWhenNoToolChoiceIsSet(): void
    {
        $options = new TextGenerationOptions(maxSteps: 3);

        $this->assertSame($options, $options->forStep(2));
    }
}
