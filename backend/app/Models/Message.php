<?php

namespace App\Models;

use App\Http\Controllers\StorageFileController;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['sender_id', 'receiver_id', 'body', 'is_read', 'file_url', 'file_type', 'file_name'];

    protected $casts = ['is_read' => 'boolean'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * File-serving architecture (principal-engineering directive): the file_url
     * column now stores just the disk-relative path (e.g. "messages/abc.png"),
     * written that way by ChatController::upload(). This accessor turns that raw
     * path into a short-lived signed URL scoped to whoever is currently
     * authenticated and reading this message (auth()->id()) — sender or
     * receiver, whichever of them is viewing the conversation right now — via
     * StorageFileController::signedUrl(). See that controller's docblock for why
     * a signed URL, not a raw storage URL or an auth:sanctum route, is required
     * here (a plain <img src> can't attach a bearer token).
     *
     * Backward-compat: rows written before this change stored a full absolute
     * URL (asset('storage/'.$path)) instead of a bare path. Both shapes are
     * normalized to a bare path here before signing, so old messages keep
     * rendering rather than breaking on this change.
     */
    public function getFileUrlAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $viewerId = auth()->id();
        if (! $viewerId) {
            // No authenticated context (e.g. a queued job, artisan tinker) — return
            // null rather than a URL that would be scoped to nobody / would 403.
            return null;
        }

        $path = preg_replace('#^https?://[^/]+/storage/#', '', $value);
        $path = preg_replace('#^/?storage/#', '', $path);

        return StorageFileController::signedUrl($path, (int) $viewerId);
    }
}
