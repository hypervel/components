<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

trait HasRequestProperties
{
    use HasCookies;
    use HasDelay;
    use HasHeaders;
    use HasMiddleware;
    use HasOptions;
    use HasQuery;
    use HasRetryPolicy;
}
