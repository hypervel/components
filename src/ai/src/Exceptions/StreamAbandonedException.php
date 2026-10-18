<?php

declare(strict_types=1);

namespace Hypervel\Ai\Exceptions;

/**
 * Distinguish a consumer's early stop from a provider failure when recording a turn.
 */
class StreamAbandonedException extends AiException
{
}
