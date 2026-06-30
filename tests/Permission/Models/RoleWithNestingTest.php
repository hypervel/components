<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Models\RoleWithNestingTest;

use Hypervel\Tests\Permission\Fixtures\Models\Role;
use Hypervel\Tests\Permission\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RoleWithNestingTest extends TestCase
{
    /**
     * @var array<string, Role>
     */
    protected array $parentRoles = [];

    /**
     * @var array<string, Role>
     */
    protected array $childRoles = [];

    protected function setUpInCoroutine(): void
    {
        $this->setUpRoleNesting();

        $this->parentRoles = [
            'has_no_children' => Role::create(['name' => 'has_no_children']),
            'has_1_child' => Role::create(['name' => 'has_1_child']),
            'has_3_children' => Role::create(['name' => 'has_3_children']),
        ];

        $this->childRoles = [
            'has_no_parents' => Role::create(['name' => 'has_no_parents']),
            'has_1_parent' => Role::create(['name' => 'has_1_parent']),
            'has_2_parents' => Role::create(['name' => 'has_2_parents']),
            'third_child' => Role::create(['name' => 'third_child']),
        ];

        $this->parentRoles['has_1_child']->children()->attach($this->childRoles['has_2_parents']);
        $this->parentRoles['has_3_children']->children()->attach([
            $this->childRoles['has_2_parents']->getKey(),
            $this->childRoles['has_1_parent']->getKey(),
            $this->childRoles['third_child']->getKey(),
        ]);
    }

    #[DataProvider('roleCountProvider')]
    public function testItReturnsCorrectWithCountOfNestedRoles(
        string $roleGroup,
        string $index,
        string $relation,
        int $expectedCount
    ): void {
        $roles = $roleGroup === 'parent_roles' ? $this->parentRoles : $this->childRoles;
        $role = $roles[$index];
        $countFieldName = sprintf('%s_count', $relation);

        $actualCount = (int) Role::withCount($relation)->find($role->getKey())->{$countFieldName};

        $this->assertSame($expectedCount, $actualCount, sprintf(
            '%s expects %d %s, %d found',
            $role->name,
            $expectedCount,
            $relation,
            $actualCount,
        ));
    }

    /**
     * Provide nested role counts.
     */
    public static function roleCountProvider(): array
    {
        return [
            ['parent_roles', 'has_no_children', 'children', 0],
            ['parent_roles', 'has_1_child', 'children', 1],
            ['parent_roles', 'has_3_children', 'children', 3],
            ['child_roles', 'has_no_parents', 'parents', 0],
            ['child_roles', 'has_1_parent', 'parents', 1],
            ['child_roles', 'has_2_parents', 'parents', 2],
        ];
    }
}
