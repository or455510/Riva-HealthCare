<?php

namespace App\Http\Controllers;

use App\Models\DoctorVerification;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * SECURITY (Phase 0): replaces the three near-identical closures that used to live in
 * routes/api.php for GET /audio/{path}, /files/{path} and /storage/{path}.
 *
 * Correction vs. the original audit: all three routes already sat inside the
 * auth:sanctum middleware group, so they were not fully public. The real problem is
 * that they had *zero ownership check* beyond "is logged in" — any authenticated
 * patient, doctor or caregiver could read any other user's file by guessing or
 * enumerating the stored path (doctor ID/license scans, other patients' chat
 * attachments). That is a textbook IDOR on medical documents, not a cosmetic gap.
 *
 * This controller keeps the same physical storage layout (no files are moved) but
 * adds a real authorization check per known path prefix:
 *  - profile_images/*        -> any authenticated user may view (already shown
 *                                 across the app in doctor/patient listings).
 *  - doctor-verifications/*  -> only the owning doctor or an admin.
 *  - messages/*               -> only the sender or receiver of the message that
 *                                 attached that specific file.
 *  - anything else            -> denied. If a new upload category is added later,
 *                                 it must be added here explicitly rather than
 *                                 falling through to "allowed".
 *
 * Also closes a path-traversal gap: the route's `.*` constraint on {path} would
 * otherwise let '../' segments escape the public disk root (see the realpath()
 * check in show()).
 *
 * FILE-SERVING ARCHITECTURE (principal-engineering directive / file-serving slice):
 * show() above requires a Sanctum bearer token, which a plain <img src="..."> /
 * <a href> cannot attach — a browser has no way to add an Authorization header to
 * a resource fetch it initiates itself. That made the ownership check in show()
 * effectively unusable from the actual rendering paths (doctor-verification
 * documents in the admin review UI, chat attachments in chat.component.ts), which
 * is why DoctorVerification::publicUrl() and ChatController::upload() previously
 * generated raw Storage::disk('public')->url()/asset('storage/...') links instead
 * — those bypass Laravel's routing/auth entirely via the public/storage symlink.
 *
 * showSigned() below is the fix: a short-lived, per-viewer Laravel signed URL
 * (Illuminate\Routing\Middleware\ValidateSignature, framework default 'signed'
 * alias) that a plain <img>/<a> CAN use unmodified, because the signature (HMAC
 * over the full query string, keyed by APP_KEY) is itself the credential — no
 * header required. The viewer's user id travels inside the signed query string
 * (?uid=), so it cannot be swapped for someone else's id without invalidating the
 * signature; the same per-prefix ownership check as show() is then re-applied
 * against that verified uid. signedUrl() below is the only place these links are
 * minted, and it is only ever called from server-side accessors (Message::
 * getFileUrlAttribute(), DoctorVerification::publicUrl()) using auth()->id() at
 * the moment a resource is serialized to the currently authenticated requester —
 * so a URL can only ever be minted for whoever is legitimately asking right now,
 * and it expires (default 30 min) rather than being a permanent link.
 *
 * Residual, deliberately-not-closed-in-this-slice gap: storage/app/public files
 * are still reachable directly through the public/storage symlink as plain
 * static files, bypassing Laravel (and therefore both show() and showSigned())
 * entirely, IF an attacker already knows/guesses the exact stored filename.
 * Laravel's Storage::store() generates cryptographically random filenames (not
 * sequential ids), so blind enumeration is not practically feasible — but this
 * is defense-by-obscurity, not a real access control, for that one residual
 * vector. Fully closing it means removing the public/storage symlink so ALL file
 * reads (including the legitimately-public profile_images/* category) go
 * through Laravel, which needs a wider audit of every place the frontend renders
 * a storage-backed image before it's safe to do without breaking something else
 * — flagged as follow-up, not attempted here, per the "no Big Bang" instruction.
 *
 * RELIABILITY (Phase 0 / S2): this used to resolve files with the raw
 * storage_path('app/public/...') helper instead of going through the Storage
 * facade/disk abstraction. Two concrete problems that caused: (1) Storage::fake()
 * in tests writes to a separate fake-disk root, so storage_path() could never find
 * files written during a test, which is exactly what StorageFileAccessTest hit;
 * (2) if the "public" disk is ever reconfigured to an S3-compatible bucket (the
 * planned move for medical files at real scale), storage_path() would keep
 * resolving a local path that no longer holds the file, breaking file serving
 * silently. Resolving everything through Storage::disk('public') fixes both.
 */
class StorageFileController extends Controller
{
    // No return type hint: response()->file() returns
    // Symfony\Component\HttpFoundation\BinaryFileResponse, which is NOT a subtype of
    // Illuminate\Http\Response (they are siblings) — declaring `: Response` here would
    // throw a TypeError at request time despite passing php -l/static review.
    public function show(Request $request, string $path)
    {
        [$disk, $normalized, $fullPath] = $this->resolvePath($path);
        abort_unless($this->authorizeAccess($normalized, $request->user()), 403);

        return response()->file($fullPath, [
            'Content-Type' => $disk->mimeType($normalized) ?: 'application/octet-stream',
        ]);
    }

    /**
     * Signed-URL counterpart to show() — see the class docblock above. Reachable
     * without a Sanctum bearer token (the 'signed' middleware, applied on the
     * route, is the credential instead), so this is the only endpoint a plain
     * <img src>/<a href> can actually use to fetch an authorized private file.
     */
    public function showSigned(Request $request, string $path)
    {
        // Defense in depth: the route already carries the 'signed' middleware
        // (Illuminate\Routing\Middleware\ValidateSignature), which would reject an
        // invalid/expired/tampered signature before this method ever runs. This
        // check is redundant on the current route wiring but cheap, and protects
        // against this action ever being reused on a route that forgets it.
        abort_unless($request->hasValidSignature(), 403);

        $viewer = User::find((int) $request->query('uid'));
        abort_unless($viewer, 403);

        [$disk, $normalized, $fullPath] = $this->resolvePath($path);
        abort_unless($this->authorizeAccess($normalized, $viewer), 403);

        return response()->file($fullPath, [
            'Content-Type' => $disk->mimeType($normalized) ?: 'application/octet-stream',
        ]);
    }

    /**
     * Mints a short-lived signed URL for $path, scoped to $viewerId. Only call
     * this with the id of whoever is actually, currently authenticated as the
     * requester (i.e. auth()->id() inside a resource/accessor serializing data
     * back to them) — never with an arbitrary/other user's id.
     */
    public static function signedUrl(string $path, int $viewerId, int $minutes = 30): string
    {
        return URL::temporarySignedRoute('files.signed', now()->addMinutes($minutes), [
            'path' => ltrim($path, '/'),
            'uid' => $viewerId,
        ]);
    }

    /**
     * @return array{0: Filesystem, 1: string, 2: string}
     */
    private function resolvePath(string $path): array
    {
        $normalized = ltrim($path, '/');
        $disk = Storage::disk('public');
        $baseDir = realpath($disk->path(''));
        $resolved = realpath($disk->path($normalized));

        // SECURITY (Phase 0): the route's `.*` path constraint accepts '../' segments,
        // so without this check a request like /api/files/..%2F..%2F..%2Fapp%2F.env
        // could resolve outside the public disk root entirely. realpath() collapses
        // any '..' traversal and we reject anything that lands outside that root.
        abort_unless($resolved && $baseDir && str_starts_with($resolved, $baseDir.DIRECTORY_SEPARATOR), 404);
        abort_unless($disk->exists($normalized), 404);

        return [$disk, $normalized, $resolved];
    }

    private function authorizeAccess(string $normalized, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (str_starts_with($normalized, 'profile_images/')) {
            // Profile photos are already surfaced to any authenticated user via
            // doctor/patient/caregiver listings — no additional check needed.
            return true;
        }

        if (str_starts_with($normalized, 'doctor-verifications/')) {
            $verification = DoctorVerification::with('doctor')
                ->where('national_id_file', $normalized)
                ->orWhere('medical_license_file', $normalized)
                ->orWhere('syndicate_card_file', $normalized)
                ->orWhere('certificate_file', $normalized)
                ->first();

            return $verification && ($user->role === 'admin' || $verification->doctor?->user_id === $user->id);
        }

        if (str_starts_with($normalized, 'messages/')) {
            $message = Message::where('file_url', 'like', '%'.$normalized)->first();

            return $message && in_array($user->id, [$message->sender_id, $message->receiver_id], true);
        }

        return false;
    }
}
