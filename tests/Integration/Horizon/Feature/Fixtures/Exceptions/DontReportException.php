<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Exceptions;

use Exception;
use Hypervel\Contracts\Debug\ShouldntReport;

class DontReportException extends Exception implements ShouldntReport
{
}
