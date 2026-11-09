<?php

namespace ModelPropertyModelFactory;

function foo(): void
{
    \App\User::factory()->createOne(['foo' => 'bar']);
    \App\User::factory()->createQuietly(['foo' => 'bar']);
    \App\User::factory()->createMany([['foo' => 'bar']]);
}
