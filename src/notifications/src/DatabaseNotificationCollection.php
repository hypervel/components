<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Database\Eloquent\Collection;

/**
 * @template TKey of array-key
 * @template TModel of DatabaseNotification
 *
 * @extends \Hypervel\Database\Eloquent\Collection<TKey, TModel>
 */
class DatabaseNotificationCollection extends Collection
{
    /**
     * Mark all notifications as read.
     */
    public function markAsRead(): void
    {
        $this->each->markAsRead();
    }

    /**
     * Mark all notifications as unread.
     */
    public function markAsUnread(): void
    {
        $this->each->markAsUnread();
    }
}
