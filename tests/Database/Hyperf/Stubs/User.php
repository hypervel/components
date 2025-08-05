<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    protected ?string $table = 'user';

    protected array $fillable = ['name'];

    public function getConnection(): ConnectionInterface
    {
        return ApplicationContext::getContainer()->get(ConnectionResolverInterface::class)->connection();
    }
}
