<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Database\Eloquent\Relations\MorphMany;

trait HasDatabaseNotifications
{
    /**
     * Get the entity's notifications.
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')
            ->latest();
    }

    /**
     * Get the entity's read notifications.
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function readNotifications(): MorphMany
    {
        return $this->notifications()->read(); /* @phpstan-ignore method.notFound */
    }

    /**
     * Get the entity's unread notifications.
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function unreadNotifications(): MorphMany
    {
        return $this->notifications()->unread(); /* @phpstan-ignore method.notFound */
    }
}
