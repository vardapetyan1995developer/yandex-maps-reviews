<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum decides a request is first-party from the Origin header, and
        // only then attaches a session. Without it the tests would exercise
        // token mode rather than the SPA flow the application actually uses.
        $this->withHeader('Origin', config('app.url'));
    }
}
