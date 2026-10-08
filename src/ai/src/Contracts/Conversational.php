<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Messages\Message;

interface Conversational
{
    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable;
}
