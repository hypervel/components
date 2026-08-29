<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\TestViewTest;

use Closure;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testing\TestComponent;
use Hypervel\Testing\TestView;
use Hypervel\Tests\TestCase;
use Hypervel\View\Component;
use Hypervel\View\View;
use Mockery as m;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

class TestViewTest extends TestCase
{
    public function testViewDataAssertionsSupportKeysValuesClosuresAndLists(): void
    {
        $view = $this->makeTestView([
            'user' => ['name' => 'Taylor'],
            'count' => 2,
        ]);

        $view
            ->assertViewHas('user.name')
            ->assertViewHas('user.name', 'Taylor')
            ->assertViewHas('count', static fn (int $count): bool => $count === 2)
            ->assertViewHas(['user.name', 'count' => 2])
            ->assertViewHasAll(['user.name', 'count' => 2])
            ->assertViewMissing('missing');
    }

    #[DataProvider('failingViewDataAssertions')]
    public function testViewDataAssertionsReportFailures(array $data, Closure $assertion): void
    {
        $this->expectException(AssertionFailedError::class);

        $assertion($this->makeTestView($data));
    }

    public static function failingViewDataAssertions(): array
    {
        return [
            'missing key' => [[], static fn (TestView $view) => $view->assertViewHas('missing')],
            'different value' => [['foo' => 'bar'], static fn (TestView $view) => $view->assertViewHas('foo', 'baz')],
            'rejected closure' => [['count' => 1], static fn (TestView $view) => $view->assertViewHas('count', static fn (int $count): bool => $count > 1)],
            'missing list binding' => [['foo' => 'bar'], static fn (TestView $view) => $view->assertViewHasAll(['foo', 'missing'])],
            'present key' => [['foo' => 'bar'], static fn (TestView $view) => $view->assertViewMissing('foo')],
        ];
    }

    public function testAssertViewHasAcceptsTheExactKeylessModel(): void
    {
        $model = new TestModel;

        $this->makeTestView(['foo' => $model])->assertViewHas('foo', $model);
    }

    public function testAssertViewHasRejectsADifferentKeylessModel(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foo' => new TestModel])->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasAcceptsAModelWithTheSameStoredIdentity(): void
    {
        $this->makeTestView(['foo' => new TestModel(['id' => 1])])
            ->assertViewHas('foo', new TestModel(['id' => 1]));
    }

    public function testAssertViewHasAcceptsACollectionContainingTheExactKeylessModels(): void
    {
        $collection = new EloquentCollection([new TestModel, new TestModel]);

        $this->makeTestView(['foos' => $collection])->assertViewHas('foos', $collection);
    }

    public function testAssertViewHasRejectsACollectionContainingDifferentKeylessModels(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView([
            'foos' => new EloquentCollection([new TestModel, new TestModel]),
        ])->assertViewHas('foos', new EloquentCollection([new TestModel, new TestModel]));
    }

    public function testAssertViewHasAcceptsACollectionWithTheSameStoredIdentities(): void
    {
        $this->makeTestView([
            'foos' => new EloquentCollection([
                new TestModel(['id' => 1]),
                new TestModel(['id' => 2]),
            ]),
        ])->assertViewHas('foos', new EloquentCollection([
            new TestModel(['id' => 1]),
            new TestModel(['id' => 2]),
        ]));
    }

    public function testAssertViewHasRejectsANonModelAsAnAssertionFailure(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foo' => 'not a model'])->assertViewHas('foo', new TestModel);
    }

    public function testAssertViewHasReportsAMissingCollectionKeyAsAnAssertionFailure(): void
    {
        $actual = new EloquentCollection([
            0 => new TestModel(['id' => 1]),
            1 => new TestModel(['id' => 2]),
        ]);
        $expected = new EloquentCollection([
            0 => new TestModel(['id' => 1]),
            2 => new TestModel(['id' => 2]),
        ]);

        $this->expectException(AssertionFailedError::class);

        $this->makeTestView(['foos' => $actual])->assertViewHas('foos', $expected);
    }

    public function testRenderedViewAssertionsSupportArraysHtmlAndNormalizedText(): void
    {
        $view = $this->makeTestView(
            [],
            "<main><p>Hello&nbsp;<strong>beautiful</strong>\u{2003}World</p><span>0</span></main>",
        );

        $view
            ->assertSee(['beautiful', 'World'])
            ->assertSeeHtml(['<main>', '<strong>beautiful</strong>'])
            ->assertSeeHtmlInOrder(['<main>', '<span>0</span>'])
            ->assertSeeText(['Hello beautiful World', '0'])
            ->assertSeeTextInOrder(['Hello', 'beautiful', 'World', '0'])
            ->assertDontSee(['Goodbye', '<footer>'])
            ->assertDontSeeHtml(['<footer>', '<span>1</span>'])
            ->assertDontSeeText(['Goodbye World', '1']);
    }

    public function testRenderedViewAssertionsEscapeExpectedValues(): void
    {
        $view = $this->makeTestView([], '<p>&lt;script&gt;</p> <strong>raw</strong>');

        $view
            ->assertSee('<script>')
            ->assertSeeInOrder(['<script>', 'raw'])
            ->assertSeeText('<script> raw')
            ->assertSeeTextInOrder(['<script>', 'raw'])
            ->assertDontSee('<style>')
            ->assertDontSeeText('<style>');
    }

    #[DataProvider('failingRenderedViewAssertions')]
    public function testRenderedViewAssertionsReportFailures(Closure $assertion): void
    {
        $this->expectException(AssertionFailedError::class);

        $assertion($this->makeTestView([], '<p>First</p><p>Second</p>'));
    }

    public static function failingRenderedViewAssertions(): array
    {
        return [
            'missing content' => [static fn (TestView $view) => $view->assertSee('missing')],
            'wrong content order' => [static fn (TestView $view) => $view->assertSeeInOrder(['Second', 'First'])],
            'missing text' => [static fn (TestView $view) => $view->assertSeeText('missing')],
            'wrong text order' => [static fn (TestView $view) => $view->assertSeeTextInOrder(['Second', 'First'])],
            'present excluded content' => [static fn (TestView $view) => $view->assertDontSee('First')],
            'present excluded text' => [static fn (TestView $view) => $view->assertDontSeeText('First')],
        ];
    }

    public function testAssertViewEmptyAndStringConversion(): void
    {
        $view = $this->makeTestView([], '');

        $this->assertSame('', (string) $view->assertViewEmpty());
    }

    public function testAssertViewEmptyRejectsRenderedContent(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->makeTestView([], 'rendered')->assertViewEmpty();
    }

    public function testRenderedComponentAssertionsSupportArraysHtmlAndNormalizedText(): void
    {
        $component = new TestComponent(
            new TestViewComponent,
            m::mock(View::class, [
                'render' => '<main><p>Hello&nbsp;<strong>World</strong></p><span>0</span></main>',
            ]),
        );

        $component
            ->assertSee(['Hello', 'World'])
            ->assertSeeHtml(['<main>', '<strong>World</strong>'])
            ->assertSeeHtmlInOrder(['<main>', '<span>0</span>'])
            ->assertSeeText(['Hello World', '0'])
            ->assertSeeTextInOrder(['Hello', 'World', '0'])
            ->assertDontSee(['Goodbye', '<footer>'])
            ->assertDontSeeHtml(['<footer>', '<span>1</span>'])
            ->assertDontSeeText(['Goodbye World', '1']);
    }

    public function testRenderedViewTextRetainsMalformedUtf8Matching(): void
    {
        $view = $this->makeTestView([], "<p>Hello \xFF World</p>");

        $view
            ->assertSeeText("Hello \xFF World", escape: false)
            ->assertDontSeeText("Goodbye \xFF World", escape: false);
    }

    /**
     * Create a test view with the given data and rendered content.
     */
    private function makeTestView(array $data, string $rendered = 'hello world'): TestView
    {
        return new TestView(m::mock(View::class, [
            'render' => $rendered,
            'gatherData' => $data,
        ]));
    }
}

class TestModel extends Model
{
    protected array $guarded = [];
}

class TestViewComponent extends Component
{
    /**
     * Get the component view.
     */
    public function render(): string
    {
        return '';
    }
}
