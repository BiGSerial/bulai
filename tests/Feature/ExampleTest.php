<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_shows_the_public_landing_page_to_guests(): void
    {
        $this
            ->get('/')
            ->assertOk();
    }
}
