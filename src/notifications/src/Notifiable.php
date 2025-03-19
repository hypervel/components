<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

trait Notifiable
{
    use HasDatabaseNotifications;
    use RoutesNotifications;
}
