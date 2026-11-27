<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Phase 1 Slice 2 / section 24: this used to be the unmodified Laravel
 * scaffold test asserting GET '/' returns 200 via view('welcome') — but this
 * project is API-only (no resources/views directory exists at all), so that
 * view was never created and the test failed permanently, not because of any
 * regression. Replaced with a real assertion against the actual API-only root
 * response now served by routes/web.php.
 */
class ExampleTest extends TestCase
{
    public function test_the_root_endpoint_returns_a_successful_json_response(): void
    {
        $response = $this->getJson('/');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
