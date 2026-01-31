<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentModelCustomEventsTest;

use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentModelCustomEventsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(CustomEvent::class, function () {
            $_SERVER['fired_event'] = true;
        });
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_model1', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('eloquent_model_stub_with_custom_event_from_traits', function (Blueprint $table) {
            $table->boolean('custom_attribute');
            $table->boolean('observer_attribute');
        });
    }

    public function testFlushListenersClearsCustomEvents()
    {
        $_SERVER['fired_event'] = false;

        TestModel1::flushEventListeners();

        TestModel1::create();

        $this->assertFalse($_SERVER['fired_event']);
    }

    public function testCustomEventListenersAreFired()
    {
        $_SERVER['fired_event'] = false;

        TestModel1::create();

        $this->assertTrue($_SERVER['fired_event']);
    }

    public function testAddObservableEventFromTrait()
    {
        $model = new EloquentModelStubWithCustomEventFromTrait();

        $this->assertNull($model->custom_attribute);
        $this->assertNull($model->observer_attribute);

        $model->completeCustomAction();

        $this->assertTrue($model->custom_attribute);
        $this->assertTrue($model->observer_attribute);
    }
}

class TestModel1 extends Model
{
    public array $dispatchesEvents = ['created' => CustomEvent::class];

    public ?string $table = 'test_model1';

    public bool $timestamps = false;

    protected array $guarded = [];
}

class CustomEvent
{
}

trait CustomEventTrait
{
    public function completeCustomAction()
    {
        $this->custom_attribute = true;

        $this->fireModelEvent('customEvent');
    }

    public function initializeCustomEventTrait()
    {
        $this->addObservableEvents([
            'customEvent',
        ]);
    }
}

class CustomObserver
{
    public function customEvent(EloquentModelStubWithCustomEventFromTrait $model)
    {
        $model->observer_attribute = true;
    }
}

#[ObservedBy(CustomObserver::class)]
class EloquentModelStubWithCustomEventFromTrait extends Model
{
    use CustomEventTrait;

    public bool $timestamps = false;
}
