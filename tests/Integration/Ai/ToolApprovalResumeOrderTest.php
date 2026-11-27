<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingMultiStepApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\ApprovableNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class ToolApprovalResumeOrderTest extends TestCase
{
    use RefreshDatabase;

    public function testASecondResumeReplaysAMixedBatchSoTheThinkingSignedAfterTheFirstResumeStillVerifies(): void
    {
        requiresApiKey('ANTHROPIC_API_KEY');

        config(['ai.conversations.generate_title' => false]);
        config(['ai.providers.anthropic.anthropic_beta' => 'thinking-binding-controls-2026-08-01']);

        $user = (object) ['id' => 1];

        $paused = (new MixedApprovalOrderAgent)->forUser($user)->prompt('Generate the numbers.', provider: 'anthropic', model: 'claude-sonnet-5-5');

        $this->assertSame(['ApprovableNumberGenerator', 'FixedNumberGenerator'], $paused->toolCalls->pluck('name')->all());

        $pausedAgain = (new MixedApprovalOrderAgent)->continue($paused->conversationId, $user)
            ->prompt(Decisions::from([$paused->pendingApprovals[0]->id => true]), provider: 'anthropic', model: 'claude-sonnet-5-5');

        $this->assertTrue($pausedAgain->hasPendingApprovals());

        $resumed = (new MixedApprovalOrderAgent)->continue($paused->conversationId, $user)
            ->prompt(Decisions::from([$pausedAgain->pendingApprovals[0]->id => true]), provider: 'anthropic', model: 'claude-sonnet-5-5');

        $this->assertFalse($resumed->hasPendingApprovals());
        $this->assertNotEmpty($resumed->text);
    }
}

class MixedApprovalOrderAgent extends RememberingMultiStepApprovableAgent implements HasProviderOptions
{
    /**
     * Get the instructions for the agent.
     */
    public function instructions(): string
    {
        return 'In your first response, call ApprovableNumberGenerator and FixedNumberGenerator together, ApprovableNumberGenerator first. '
            . 'Once you have both results, call ApprovableNumberGenerator exactly once more, alone. Then reply with one short sentence listing the numbers. '
            . 'Before every tool call, think carefully about whether the numbers so far look cryptographically plausible.';
    }

    /**
     * Get the available tools.
     */
    public function tools(): iterable
    {
        return [new ApprovableNumberGenerator, new FixedNumberGenerator];
    }

    /**
     * Require strict thinking-block verification.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return [
            'thinking' => ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'error']],
            'output_config' => ['effort' => 'max'],
        ];
    }
}
