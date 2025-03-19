<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hyperf\Database\Model\Relations\MorphMany;
use Hyperf\Database\Query\Builder;

trait HasDatabaseNotifications
{
    /**
     * Get the entity's notifications.
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')
            ->latest();
    }

    /**
     * Get the entity's read notifications.
     */
    public function readNotifications(): Builder
    {
        return $this->notifications()->read();
    }

    /**
     * Get the entity's unread notifications.
     */
    public function unreadNotifications(): Builder
    {
        return $this->notifications()->unread();
    }
}
