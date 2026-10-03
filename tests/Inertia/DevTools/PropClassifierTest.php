<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Http\Request;
use Hypervel\Inertia\DevTools\Data\PropType;
use Hypervel\Inertia\DevTools\DevToolsHeader;
use Hypervel\Inertia\DevTools\PropClassifier;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Support\Header;
use Hypervel\Tests\Inertia\TestCase;

/**
 * Per-branch matrix for PropClassifier. Each test exercises exactly one classification
 * branch and asserts the normalized array matches the wire values the extension renders
 * (see packages/devtools-extension/src/types.ts PropType and PropMeta).
 */
class PropClassifierTest extends TestCase
{
    /**
     * Classify the prop for a request with the given headers.
     *
     * @param array<string, string> $headers
     * @return array{inertiaType: ?PropType, deferGroup: ?string, reset: bool, once: bool, mergeDirection: ?string, deepMerge: bool}
     */
    private function classify(string $path, mixed $prop, array $headers = []): array
    {
        $request = Request::create('/x', 'GET');

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        return (new PropClassifier)->classifyResolved($path, $prop, $request);
    }

    public function testAlwaysPropClassifiesAsAlways(): void
    {
        $result = $this->classify('user', Inertia::always('value'));

        $this->assertSame(PropType::Always, $result['inertiaType']);
        $this->assertNull($result['deferGroup']);
        $this->assertNull($result['mergeDirection']);
        $this->assertFalse($result['deepMerge']);
        $this->assertFalse($result['once']);
        $this->assertFalse($result['reset']);
    }

    public function testOptionalPropClassifiesAsOptional(): void
    {
        $result = $this->classify('bio', Inertia::optional(fn (): string => 'value'));

        $this->assertSame(PropType::Optional, $result['inertiaType']);
        $this->assertNull($result['deferGroup']);
        $this->assertNull($result['mergeDirection']);
        $this->assertFalse($result['deepMerge']);
    }

    public function testMergePropClassifiesAsMerge(): void
    {
        $result = $this->classify('items', Inertia::merge(['a']));

        $this->assertSame(PropType::Merge, $result['inertiaType']);
    }

    public function testScrollPropClassifiesAsScroll(): void
    {
        $result = $this->classify('feed', Inertia::scroll(['a']));

        $this->assertSame(PropType::Scroll, $result['inertiaType']);
    }

    public function testOncePropClassifiesAsOnceAndFlagsOnce(): void
    {
        $result = $this->classify('config', Inertia::once(fn (): string => 'value'));

        $this->assertSame(PropType::Once, $result['inertiaType']);
        $this->assertTrue($result['once']);
    }

    public function testPlainValueHasNoInertiaType(): void
    {
        $result = $this->classify('name', 'Alice');

        $this->assertNull($result['inertiaType']);
        $this->assertNull($result['deferGroup']);
        $this->assertNull($result['mergeDirection']);
        $this->assertFalse($result['deepMerge']);
        $this->assertFalse($result['once']);
    }

    public function testDeferPropOutsideADeferredRequestReadsAsRegular(): void
    {
        $result = $this->classify('stats', Inertia::defer(fn (): string => 'value', 'groupA'));

        $this->assertNull($result['inertiaType']);
        $this->assertNull($result['deferGroup']);
    }

    public function testDeferPropInADeferredRequestClassifiesAsDeferWithGroup(): void
    {
        $result = $this->classify('stats', Inertia::defer(fn (): string => 'value', 'groupA'), [
            DevToolsHeader::DEVTOOLS_DEFERRED => '1',
        ]);

        $this->assertSame(PropType::Defer, $result['inertiaType']);
        $this->assertSame('groupA', $result['deferGroup']);
    }

    public function testDeferPropInADeferredRequestFallsBackToTheDefaultGroup(): void
    {
        $result = $this->classify('stats', Inertia::defer(fn (): string => 'value'), [
            DevToolsHeader::DEVTOOLS_DEFERRED => '1',
        ]);

        $this->assertSame(PropType::Defer, $result['inertiaType']);
        $this->assertSame('default', $result['deferGroup']);
    }

    public function testScrollPropDoesNotCarryADeferGroupUnlessDeferred(): void
    {
        $result = $this->classify('feed', Inertia::scroll(['a']));

        $this->assertNull($result['deferGroup']);
    }

    public function testDeferrableNonDeferPropCarriesItsGroupWhenDeferred(): void
    {
        $result = $this->classify('feed', Inertia::scroll(['a'])->defer('scrollGroup'), [
            DevToolsHeader::DEVTOOLS_DEFERRED => '1',
        ]);

        $this->assertSame('scrollGroup', $result['deferGroup']);
        $this->assertSame(PropType::Scroll, $result['inertiaType']);
    }

    public function testOnceFlagTracksOnceableStateIndependentOfType(): void
    {
        $mergeOnce = $this->classify('items', Inertia::merge(['a'])->once());
        $this->assertSame(PropType::Merge, $mergeOnce['inertiaType']);
        $this->assertTrue($mergeOnce['once']);

        $mergePlain = $this->classify('items', Inertia::merge(['a']));
        $this->assertFalse($mergePlain['once']);
    }

    public function testDeferredOncePropFlagsOnce(): void
    {
        $result = $this->classify('stats', Inertia::defer(fn (): string => 'value')->once(), [
            DevToolsHeader::DEVTOOLS_DEFERRED => '1',
        ]);

        $this->assertSame(PropType::Defer, $result['inertiaType']);
        $this->assertTrue($result['once']);
    }

    public function testRootAppendMergeDirection(): void
    {
        $result = $this->classify('items', Inertia::merge(['a']));

        $this->assertSame('append', $result['mergeDirection']);
    }

    public function testRootPrependMergeDirection(): void
    {
        $result = $this->classify('items', Inertia::merge(['a'])->prepend());

        $this->assertSame('prepend', $result['mergeDirection']);
    }

    public function testNestedAppendMergeDirection(): void
    {
        $result = $this->classify('items', Inertia::merge(['a'])->append('rows'));

        $this->assertSame('append', $result['mergeDirection']);
    }

    public function testNestedPrependMergeDirection(): void
    {
        $result = $this->classify('items', Inertia::merge(['a'])->prepend('rows'));

        $this->assertSame('prepend', $result['mergeDirection']);
    }

    public function testMixedNestedDirectionReadsAsAppend(): void
    {
        $result = $this->classify('items', Inertia::merge(['a'])->append('rows')->prepend('cols'));

        $this->assertSame('append', $result['mergeDirection']);
    }

    public function testNonMergeablePropHasNoMergeDirection(): void
    {
        $result = $this->classify('bio', Inertia::optional(fn (): string => 'value'));

        $this->assertNull($result['mergeDirection']);
    }

    public function testScrollPropDefaultDirectionIsAppend(): void
    {
        $result = $this->classify('feed', Inertia::scroll(['a']));

        $this->assertSame('append', $result['mergeDirection']);
    }

    public function testScrollPropPrependIntentDirection(): void
    {
        $result = $this->classify('feed', Inertia::scroll(['a'])->prepend('data'));

        $this->assertSame('prepend', $result['mergeDirection']);
    }

    public function testDeepMergeFlagForDeepMerge(): void
    {
        $result = $this->classify('items', Inertia::merge(['a'])->deepMerge());

        $this->assertTrue($result['deepMerge']);
        $this->assertSame('append', $result['mergeDirection']);
    }

    public function testDeepMergeFlagSurvivesPrepend(): void
    {
        $result = $this->classify('items', Inertia::merge(['a'])->deepMerge()->prepend());

        $this->assertTrue($result['deepMerge']);
        $this->assertSame('prepend', $result['mergeDirection']);
    }

    public function testMatchOnImpliesDeepMerge(): void
    {
        $result = $this->classify('items', Inertia::merge([['id' => 1]])->matchOn('id'));

        $this->assertTrue($result['deepMerge']);
        $this->assertSame('append', $result['mergeDirection']);
    }

    public function testMatchOnWithoutMergingIsNotADeepMerge(): void
    {
        $result = $this->classify('items', Inertia::defer(fn (): array => [['id' => 1]])->matchOn('id'));

        $this->assertFalse($result['deepMerge']);
        $this->assertNull($result['mergeDirection']);
    }

    public function testPlainMergeIsNotADeepMerge(): void
    {
        $result = $this->classify('items', Inertia::merge(['a']));

        $this->assertFalse($result['deepMerge']);
    }

    public function testNonMergeablePropIsNotADeepMerge(): void
    {
        $result = $this->classify('name', 'Alice');

        $this->assertFalse($result['deepMerge']);
    }

    public function testResetFlagIsSetWhenThePathIsInTheResetHeader(): void
    {
        $result = $this->classify('items', 'value', [Header::RESET => 'items']);

        $this->assertTrue($result['reset']);
    }

    public function testResetFlagIsFalseWhenThePathIsAbsentFromTheResetHeader(): void
    {
        $result = $this->classify('items', 'value', [Header::RESET => 'other']);

        $this->assertFalse($result['reset']);
    }

    public function testResetHeaderIsParsedAsACommaList(): void
    {
        $present = $this->classify('items', 'value', [Header::RESET => 'first,items,last']);
        $this->assertTrue($present['reset']);

        $absent = $this->classify('middle', 'value', [Header::RESET => 'first,items,last']);
        $this->assertFalse($absent['reset']);
    }

    public function testEmptyResetHeaderNeverMarksAPropReset(): void
    {
        $result = $this->classify('items', 'value', [Header::RESET => '']);

        $this->assertFalse($result['reset']);
    }

    public function testClassifierReadsTheExpectedHeaderNames(): void
    {
        $this->assertSame('X-Inertia-Devtools-Deferred', DevToolsHeader::DEVTOOLS_DEFERRED);
        $this->assertSame('X-Inertia-Reset', Header::RESET);
    }

    public function testPropTypeWireValuesMatchTheExtensionContract(): void
    {
        $this->assertSame('always', PropType::Always->value);
        $this->assertSame('defer', PropType::Defer->value);
        $this->assertSame('optional', PropType::Optional->value);
        $this->assertSame('merge', PropType::Merge->value);
        $this->assertSame('scroll', PropType::Scroll->value);
        $this->assertSame('once', PropType::Once->value);
    }
}
