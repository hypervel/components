<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

/**
 * A step text gateway that sends the full history on every step. Its continuation token identifies a provider resource, such as a code execution container, rather than stored history, so the tool loop keeps it when middleware changes a step's history, model or instructions.
 */
interface ReplaysFullHistory
{
}
