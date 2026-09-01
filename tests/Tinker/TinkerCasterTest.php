<?php

declare(strict_types=1);

namespace Hypervel\Tests\Tinker;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Hypervel\Tinker\TinkerCaster;
use Symfony\Component\VarDumper\Caster\Caster;

class TinkerCasterTest extends TestCase
{
    public function testCanCastCollection(): void
    {
        $result = TinkerCaster::castCollection(new Collection(['foo', 'bar']));

        $this->assertSame([['foo', 'bar']], array_values($result));
    }

    public function testCanCastModelWithoutAppendedAttributes(): void
    {
        $model = new TinkerCasterModel;
        $model->setRawAttributes([
            'name' => 'Taylor',
            'secret' => 'hidden',
        ]);
        $model->setHidden(['secret']);
        $model->setRelation('roles', $roles = new Collection(['admin']));

        $result = TinkerCaster::castModel($model);

        $this->assertSame('Taylor', $result[Caster::PREFIX_VIRTUAL . 'name']);
        $this->assertSame('hidden', $result[Caster::PREFIX_PROTECTED . 'secret']);
        $this->assertSame($roles, $result[Caster::PREFIX_VIRTUAL . 'roles']);
        $this->assertCount(3, $result);
    }

    public function testCanCastMultipleAppendedAccessorsWithVisibility(): void
    {
        $model = new TinkerCasterModel;
        $model->setRawAttributes([
            'first_name' => 'Taylor',
            'last_name' => 'Otwell',
        ]);
        $model->setAppends(['full_name', 'initials']);
        $model->setVisible(['first_name', 'full_name']);
        $model->setHidden(['last_name', 'initials']);

        $result = TinkerCaster::castModel($model);

        $this->assertSame('Taylor', $result[Caster::PREFIX_VIRTUAL . 'first_name']);
        $this->assertSame('Otwell', $result[Caster::PREFIX_PROTECTED . 'last_name']);
        $this->assertSame('Taylor Otwell', $result[Caster::PREFIX_VIRTUAL . 'full_name']);
        $this->assertSame('TO', $result[Caster::PREFIX_PROTECTED . 'initials']);
        $this->assertSame(1, $model->fullNameAccesses);
        $this->assertSame(1, $model->initialsAccesses);
        $this->assertCount(4, $result);
    }
}

class TinkerCasterModel extends Model
{
    public int $fullNameAccesses = 0;

    public int $initialsAccesses = 0;

    public function getFullNameAttribute(): string
    {
        ++$this->fullNameAccesses;

        return $this->first_name . ' ' . $this->last_name;
    }

    public function getInitialsAttribute(): string
    {
        ++$this->initialsAccesses;

        return $this->first_name[0] . $this->last_name[0];
    }
}
