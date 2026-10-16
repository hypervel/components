<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Approvals\Approval;
use Hypervel\Ai\Concerns\InteractsWithApprovals;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class ApprovableNumberGenerator implements Approvable, Tool
{
    use InteractsWithApprovals {
        shouldRequestApproval as protected traitShouldRequestApproval;
    }

    public static int $invocations = 0;

    public static int $approvalChecks = 0;

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return 'Generates a number, but requires human approval first.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        ++static::$invocations;

        return '72019';
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Determine whether the tool requires approval.
     */
    public function shouldRequestApproval(Request $request): ?Approval
    {
        ++static::$approvalChecks;

        return $this->traitShouldRequestApproval($request);
    }
}
