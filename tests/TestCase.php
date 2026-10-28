<?php

namespace Tests;

use App\Services\LocationLabel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LocationLabel::flush();
    }
}
