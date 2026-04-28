<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\ModelSerializationTest;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Queue\SerializesModels;

class TypedPropertyTestClass
{
    use SerializesModels;

    public ModelSerializationTestUser $user;

    public ModelSerializationTestUser $uninitializedUser;

    protected int $id;

    private array $names;

    public function __construct(ModelSerializationTestUser $user, int $id, array $names)
    {
        $this->user = $user;
        $this->id = $id;
        $this->names = $names;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getNames(): array
    {
        return $this->names;
    }
}

class TypedPropertyCollectionTestClass
{
    use SerializesModels;

    public Collection $users;

    public function __construct(Collection $users)
    {
        $this->users = $users;
    }
}
