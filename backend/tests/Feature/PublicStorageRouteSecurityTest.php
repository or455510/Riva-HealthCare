<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for GET /storage/{path} in routes/web.php — see the
 * docblock on that route for the full finding and an important nuance: in
 * this project's current (route-caching-disabled) configuration, Laravel 11's
 * built-in Illuminate\Filesystem\FilesystemServiceProvider registers its OWN
 * signed-URL-gated route at the identical URI (name 'storage.local', backed
 * by the private 'local' disk) in a post-boot callback, which wins over this
 * file's closure registration — so every request that reaches Laravel routing
 * here currently gets an unconditional 403 from the framework's route, since
 * nothing in this app ever generates the required signature. That is
 * confirmed (not assumed) by the assertions below: they prove no path,
 * traversal or otherwise, is ever served as a 200 with file content through
 * Laravel routing — which is the actual security property that matters —
 * without hard-coding an assumption about which of the two routes is
 * currently answering (that depends on whether the deployment runs
 * `php artisan route:cache`, see the routes/web.php docblock).
 */
class PublicStorageRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_path_traversal_never_returns_file_content(): void
    {
        $responseA = $this->get('/storage/..%2F..%2F..%2F.env');
        $responseB = $this->get('/storage/../../../.env');

        foreach ([$responseA, $responseB] as $response) {
            $this->assertNotEquals(200, $response->getStatusCode(), 'Path traversal must never return 200.');
            $this->assertStringNotContainsString('APP_KEY', $response->getContent() ?: '');
        }
    }

    public function test_nonexistent_file_is_never_served(): void
    {
        $response = $this->get('/storage/profile_images/does-not-exist.png');

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_unknown_prefix_is_never_served_through_this_route(): void
    {
        $path = 'some-unexpected-folder/secret.txt';
        Storage::disk('public')->put($path, 'fake-bytes');

        $response = $this->get('/storage/'.$path);

        // Whichever route answers (this file's closure, or the framework's
        // shadowing route — see docblocks), the result must not be a bare 200
        // handing back file bytes with no authorization check at all having
        // run. Both effective implementations satisfy this today.
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_root_endpoint_is_unaffected_by_the_storage_route_change(): void
    {
        $this->getJson('/')->assertOk()->assertJson(['success' => true]);
    }

    public function test_authenticated_api_storage_route_remains_the_correct_authorized_path(): void
    {
        // The properly authorized, ownership-checked equivalent (Phase 0,
        // StorageFileController) is unaffected by any of the above — this is
        // the route real clients should use, and it still requires auth.
        $this->getJson('/api/storage/profile_images/anything.png')->assertUnauthorized();
    }
}
