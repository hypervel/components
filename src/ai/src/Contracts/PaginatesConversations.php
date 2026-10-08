<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Storage\StoredMessage;
use Hypervel\Contracts\Pagination\CursorPaginator;
use Hypervel\Pagination\Cursor;

interface PaginatesConversations
{
    /**
     * Paginate the given conversation's messages, newest first.
     *
     * @return CursorPaginator<int, StoredMessage>
     */
    public function paginateConversationMessages(string $conversationId, int $perPage = 15, string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator;
}
