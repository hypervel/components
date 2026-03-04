<?php

declare(strict_types=1);

namespace Hypervel\Tests\Serializer\Stub;

use Exception;

class FooException extends Exception
{
    public function __construct(int $code = 0, string $message = '')
    {
        parent::__construct($message, $code);
    }
}
