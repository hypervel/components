<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit;

use Generator;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\StructuredAnonymousAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AnonymousAgentTest extends TestCase
{
    #[DataProvider('agentClasses')]
    public function testGeneratorInputsCanBeReusedAndSerialized(string $class): void
    {
        $message = new UserMessage('Earlier question');
        $tool = new NamedTool;
        $agent = new $class(
            'Be helpful',
            (static fn (): Generator => yield $message)(),
            (static fn (): Generator => yield $tool)(),
        );

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertSame([$message], [...$agent->messages()]);
            $this->assertSame([$tool], [...$agent->tools()]);
        }

        $restored = unserialize(serialize($agent));
        $this->assertEquals([$message], [...$restored->messages()]);
        $this->assertEquals([$tool], [...$restored->tools()]);
    }

    /**
     * Provide both anonymous-agent constructor paths.
     */
    public static function agentClasses(): array
    {
        return [[AnonymousAgent::class], [StructuredAnonymousAgent::class]];
    }
}
