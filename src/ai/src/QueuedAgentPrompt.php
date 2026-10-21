<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class QueuedAgentPrompt
{
    public Agent $agent;

    public string $prompt;

    public Collection|array $attachments;

    public Provider|Lab|array|string|null $provider;

    public ?string $model;

    public ?Decisions $approvalDecisions;

    /**
     * Create a new queued agent prompt instance.
     */
    public function __construct(
        Agent $agent,
        Decisions|string $prompt,
        Collection|array $attachments,
        Provider|Lab|array|string|null $provider,
        ?string $model,
    ) {
        $this->agent = $agent;
        $this->prompt = is_string($prompt) ? $prompt : '';
        $this->attachments = $attachments;
        $this->provider = $provider;
        $this->model = $model;
        $this->approvalDecisions = $prompt instanceof Decisions ? $prompt : null;
    }

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->prompt, $string);
    }

    /**
     * Determine whether the prompt has tool approval decisions.
     */
    public function hasApprovalDecisions(): bool
    {
        return $this->approvalDecisions !== null;
    }
}
