<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Tests\Permission\Fixtures\ContentPolicy;
use Hypervel\Tests\Permission\Fixtures\Models\Content;
use Hypervel\Tests\Permission\TestCase;

class PolicyTest extends TestCase
{
    public function testPolicyMethodsAndBeforeInterceptsCanAllowAndDeny(): void
    {
        $record1 = Content::create(['content' => 'special admin content']);
        $record2 = Content::create(['content' => 'viewable', 'user_id' => $this->testUser->id]);

        $this->app->make(Gate::class)->policy(Content::class, ContentPolicy::class);

        $this->assertFalse($this->testUser->can('view', $record1));
        $this->assertFalse($this->testUser->can('update', $record1));

        $this->assertTrue($this->testUser->can('update', $record2));

        $this->assertFalse($this->testAdmin->can('update', $record1));

        $this->testAdmin->assignRole($this->testAdminRole);

        $this->assertTrue($this->testAdmin->can('update', $record1));
        $this->assertTrue($this->testAdmin->can('update', $record2));
    }
}
