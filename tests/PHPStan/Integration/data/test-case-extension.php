<?php

use App\User;

class TestCaseExtension
{
    public function testMockMethod(): void
    {
        (new TestTestCase('foo'))->testMockMethod();
    }

    public function testPartialMockMethod(): void
    {
        (new TestTestCase('foo'))->testPartialMockMethod();
    }

    public function testSpyMethod(): void
    {
        (new TestTestCase('foo'))->testSpyMethod();
    }
}

// Hypervel: the testing trait declares these mock types in source, and the base TestCase
// already creates the application and always has partialMock(), so upstream's
// createApplication() override and method_exists() guard are omitted.
class TestTestCase extends \Hypervel\Foundation\Testing\TestCase
{
    public function testMockMethod(): void
    {
        $mock = $this->mock(User::class);
        $mock->accounts();
    }

    public function testPartialMockMethod(): void
    {
        $mock = $this->partialMock(User::class);
        $mock->accounts();
    }

    public function testSpyMethod(): void
    {
        $mock = $this->spy(User::class);
        $mock->accounts();
    }
}
