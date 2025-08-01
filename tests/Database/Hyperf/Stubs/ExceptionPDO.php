<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use PDO;
use PDOException;

class ExceptionPDO extends PDO
{
    public function __construct(public bool $throw)
    {
    }

    public function __destruct()
    {
        $this->throw && throw new PDOException();
    }
}
