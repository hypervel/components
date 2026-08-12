<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

class EntryType
{
    public const string BATCH = 'batch';

    public const string CACHE = 'cache';

    public const string COMMAND = 'command';

    public const string DUMP = 'dump';

    public const string EVENT = 'event';

    public const string EXCEPTION = 'exception';

    public const string JOB = 'job';

    public const string LOG = 'log';

    public const string MAIL = 'mail';

    public const string MODEL = 'model';

    public const string NOTIFICATION = 'notification';

    public const string QUERY = 'query';

    public const string REDIS = 'redis';

    public const string REVERB = 'reverb';

    public const string REQUEST = 'request';

    public const string SCHEDULED_TASK = 'schedule';

    public const string GATE = 'gate';

    public const string VIEW = 'view';

    public const string CLIENT_REQUEST = 'client_request';
}
