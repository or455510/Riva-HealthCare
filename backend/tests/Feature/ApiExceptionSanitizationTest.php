<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SECURITY: previously any uncaught exception on an API/JSON request was
 * rendered straight to the client whenever APP_DEBUG=true (the shipped
 * .env.example default) — including raw SQL, bound parameters, file paths,
 * and a full stack trace. This was confirmed reaching real end users: a raw
 * `SQLSTATE[HY000] [1130] Host 'localhost' is not allowed to connect to this
 * MariaDB server (Connection: mysql, SQL: select * from `users` where
 * `email` = ...)` message rendered directly on the Sign In screen when the
 * local MariaDB server rejected the app's DB connection. See bootstrap/app.php
 * for the fix: a catch-all Throwable renderer that logs full detail
 * server-side but always returns a generic message to API/JSON clients,
 * except for exception types Laravel already renders safely and
 * intentionally (validation, auth, HTTP-status exceptions).
 */
class ApiExceptionSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_genuine_database_query_exception_is_not_leaked_to_an_api_client(): void
    {
        Log::spy();

        Route::middleware('api')->get('/api/__test/query-exception', function () {
            // A real Illuminate\Database\QueryException — not a mock —
            // exactly the exception class family the reported bug hit.
            return DB::table('this_table_does_not_exist_xyz')->first();
        });

        $response = $this->getJson('/api/__test/query-exception');

        $response->assertStatus(500);
        $response->assertJson([
            'success' => false,
            'message' => 'Something went wrong on our end. Please try again shortly.',
        ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('this_table_does_not_exist_xyz', $body);
        $this->assertStringNotContainsString('vendor'.DIRECTORY_SEPARATOR.'laravel', $body);

        // Laravel's default reportable() logging plus our own explicit
        // Log::error() inside the render() handler below can both fire for
        // the same exception — the point of this assertion isn't the count,
        // it's that the full exception detail was captured server-side
        // somewhere, even though the client never saw it.
        Log::shouldHaveReceived('error')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context) => $message === 'Unhandled exception on API/JSON request'
                && str_contains($context['exception'] ?? '', 'QueryException')
            );
    }

    public function test_validation_errors_still_render_their_real_field_messages(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        // Untouched by the new catch-all handler: real, specific validation
        // messages the user actually needs, not a generic 500.
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_a_route_not_found_error_still_renders_normally(): void
    {
        $response = $this->getJson('/api/this-route-does-not-exist-anywhere');

        $response->assertStatus(404);
    }
}
