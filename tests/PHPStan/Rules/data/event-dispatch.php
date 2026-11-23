<?php

namespace Hypervel\Tests\PHPStan\Rules\Data;

HypervelEvent::dispatch();

HypervelEvent::dispatch('foo');
HypervelEvent::dispatch(1, 'foo');
HypervelEvent::dispatch(true, false);

HypervelEvent::dispatchIf(true, 1, 'foo');
HypervelEvent::dispatchUnless(true, 1, 1);

HypervelEventWithoutConstructor::dispatch();
HypervelEventWithoutConstructor::dispatch('foo');

HypervelEventWithoutConstructor::dispatchIf(true);
HypervelEventWithoutConstructor::dispatchUnless(true, 'foo');

// Hypervel: broadcast() constructs the event too.
HypervelEvent::broadcast('foo');

// Hypervel: dispatch() passes its arguments' values on, so a reference parameter takes any expression.
HypervelEventWithReference::dispatch(1);
HypervelEventWithReference::dispatch('foo');
