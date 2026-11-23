<?php

declare(strict_types=1);

namespace RuntimeInputs;

class ViewUsage
{
    public function helper(): string
    {
        return render_fixture('fixture-page');
    }

    public function hinted(): string
    {
        return render_fixture('fixture::panel');
    }
}
