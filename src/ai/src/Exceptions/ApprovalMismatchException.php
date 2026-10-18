<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class ApprovalMismatchException extends AiException
{
    /**
     * Create a new approval mismatch exception.
     *
     * @param Collection<int, PendingApproval> $pendingApprovals
     */
    public function __construct(string $message, public Collection $pendingApprovals)
    {
        parent::__construct($message);
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): Response
    {
        return response()->json([
            'message' => $this->getMessage(),
            'approvals' => $this->pendingApprovals->values()->toArray(),
        ], 409);
    }
}
