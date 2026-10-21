<?php

declare(strict_types=1);

namespace Hypervel\Ai\Migrations;

use Hypervel\Database\Migrations\Migration;
use Override;

abstract class AiMigration extends Migration
{
    /**
     * Get the migration connection name.
     */
    #[Override]
    public function getConnection(): ?string
    {
        return config('ai.conversations.connection', config()->string('database.default'));
    }
}
