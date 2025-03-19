<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__) . '/publish/resources/views', 'notifications');
    }
}
