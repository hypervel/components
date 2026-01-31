<?php

declare(strict_types=1);

namespace Hypervel\Database;

use InvalidArgumentException;

class SQLiteDatabaseDoesNotExistException extends InvalidArgumentException
{
    /**
     * The path to the database.
     */
    public string $path;

    /**
     * Create a new exception instance.
     */
    public function __construct(string $path)
    {
        parent::__construct("Database file at path [{$path}] does not exist. Ensure this is an absolute path to the database.");

        $this->path = $path;
    }
}
