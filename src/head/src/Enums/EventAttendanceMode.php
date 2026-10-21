<?php

declare(strict_types=1);

namespace Hypervel\Head\Enums;

enum EventAttendanceMode: string
{
    case Mixed = 'MixedEventAttendanceMode';
    case Offline = 'OfflineEventAttendanceMode';
    case Online = 'OnlineEventAttendanceMode';

    /**
     * Get the schema.org attendance mode URL.
     */
    public function url(): string
    {
        return 'https://schema.org/' . $this->value;
    }
}
