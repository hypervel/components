<?php

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Support\Env;
use Hypervel\Support\Env as Environment;
use Hypervel\Testbench\Foundation\Env as TestbenchEnv;

env_array('foo');
env_or_fail('foo');
app_id();
Env::get('foo');
Env::getArray('foo');
Env::getOrFail('foo');

// Aliases, method name case and subclasses resolve to the Env reader
Environment::GET('foo');
TestbenchEnv::get('foo');

// Other Env methods do not read a variable
Env::getRepository();
