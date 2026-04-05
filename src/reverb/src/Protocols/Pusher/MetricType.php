<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

enum MetricType: string
{
    case Connections = 'connections';
    case Channel = 'channel';
    case Channels = 'channels';
    case ChannelUsers = 'channel_users';
}
