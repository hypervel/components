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

        // test that the Admin cannot yet view 'special admin content', because doesn't have Role yet
        $this->assertFalse($this->testAdmin->can('update', $record1));

        $this->testAdmin->assignRole($this->testAdminRole);
        // test that the Admin can view 'special admin content'
        $this->assertTrue($this->testAdmin->can('update', $record1));
        $this->assertTrue($this->testAdmin->can('update', $record2));
    }
}
