<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Stub;

enum TokenAbility: string
{
    case PostsRead = 'posts:read';
    case PostsWrite = 'posts:write';
    case UsersRead = 'users:read';
    case UsersWrite = 'users:write';
}
