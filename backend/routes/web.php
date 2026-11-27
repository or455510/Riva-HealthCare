<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Phase 1 Slice 2 / section 24: this project is API-only — there is no
// resources/views directory at all, so the default Laravel scaffold's
// view('welcome') here was permanently broken (View [welcome] not found),
// which is exactly what the stock ExampleTest was failing on. Replaced with a
// minimal JSON root response appropriate for an API-only app; see the
// corresponding real assertion in tests/Feature/ExampleTest.php.
Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Riva API is running.',
    ]);
});

/**
 * SECURITY (Phase 1 Slice 2): this closure used to build
 * storage_path('app/public/'.$path) directly from the raw route segment with
 * NO containment check at all, unlike app/Http/Controllers/StorageFileController
 * (the authenticated API equivalent, fixed in Phase 0), which resolves the path
 * through realpath() and verifies it stays inside the public disk root. Fixed
 * below using that same containment check.
 *
 * IMPORTANT nuance discovered while writing the regression test for this fix:
 * Laravel 11's default config/filesystems.php ships the 'local' disk with
 * 'serve' => true and no 'url' override, which makes
 * Illuminate\Filesystem\FilesystemServiceProvider register its OWN
 * GET /storage/{path} route (name 'storage.local', backed by the PRIVATE
 * 'local' disk, gated behind Illuminate\Filesystem\ServeFile's signed-URL
 * check) in a post-boot callback — i.e. AFTER this file's routes are
 * registered. Because both routes share the identical method+URI, Laravel's
 * RouteCollection keys routes by "method+URI" and the later registration wins,
 * so in this project's current (route-caching-disabled) configuration, THIS
 * closure is actually shadowed and never executes — every request to
 * /storage/{path} that reaches Laravel routing gets an unconditional 403 from
 * the framework's route instead (nothing in this app ever generates the
 * required signature). Confirmed via tests/Feature/PublicStorageRouteSecurityTest.
 * If this project is ever deployed with `php artisan route:cache` (a common
 * production optimization), FilesystemServiceProvider explicitly skips its own
 * registration when routes are cached — at which point THIS closure becomes
 * the actual effective, reachable route again. The fix below is kept
 * (harmless either way, and load-bearing under route caching) rather than
 * removed.
 *
 * Neither path is a full fix for the deeper, separate, real gap: any file that
 * physically exists under storage/app/public is reachable via the
 * public/storage symlink as a plain static file served directly by the web
 * server, bypassing Laravel (and therefore both routes above, and
 * StorageFileController's per-user ownership checks) entirely. That is a
 * structural issue — profile photos need this (logged-out-visible doctor/
 * caregiver listings use plain <img src>), but doctor-verification documents
 * and private chat attachments end up reachable the same "if you know/guess
 * the filename" way, since the current frontend renders file_url directly
 * with no auth header (see chat.component.ts). Closing that properly requires
 * a coordinated frontend change (signed URLs, or authenticated blob-fetch
 * rendering) — flagged as REQUIRES OWNER DECISION, not fixed here, since it
 * cannot be done safely without breaking currently-working image/attachment
 * display in the same pass.
 */
Route::get('/storage/{path}', function (string $path) {
    $disk = Storage::disk('public');
    $normalized = ltrim($path, '/');
    $baseDir = realpath($disk->path(''));
    $resolved = realpath($disk->path($normalized));

    abort_unless($resolved && $baseDir && str_starts_with($resolved, $baseDir.DIRECTORY_SEPARATOR), 404);
    abort_unless($disk->exists($normalized), 404);

    return response()->file($resolved, [
        'Content-Type' => $disk->mimeType($normalized) ?: 'application/octet-stream',
    ]);
})->where('path', '.*');
